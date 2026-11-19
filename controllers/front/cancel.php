<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Repository\TransactionRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The customer clicked Cancel on CMI's payment page (shopurl). The order is cancelled only when
 * CMI shows no payment for it: this link never undoes a payment, only the merchant can void
 * or refund one.
 *
 * @property Cmi $module
 */
class CmiCancelModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $order = new Order((int) Tools::getValue('id_order'));
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name
            || !hash_equals((string) $order->secure_key, (string) Tools::getValue('key'))) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }
        $services = $this->module->services();
        $repository = $services->repository();
        $transaction = $repository->findByOrder($order);
        if ($transaction && $repository->acquireLockWaiting($transaction, 5)) {
            try {
                $transaction = $repository->refresh($transaction);
                $this->cancel($transaction);
            } finally {
                $repository->releaseLock($transaction);
            }
        }
        Tools::redirect($this->context->link->getModuleLink('cmi', 'return', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true));
    }

    private function cancel(Cmi\PrestaShop\Entity\CmiTransaction $transaction): void
    {
        $services = $this->module->services();
        $settings = $services->settings();
        if ($services->orders()->isPaid($transaction) || !PaymentState::isAwaitingPayment((string) $transaction->payment_state)) {
            $allowed = false;
            $check = (string) $transaction->payment_state;
        } else {
            $check = '';
            if ($settings->isApiEnabled()) {
                $check = (string) $services->manager()->sync($transaction)['status'];
            }
            $allowed = PaymentState::customerCancelAllowed($check)
                && PaymentState::isAwaitingPayment((string) $transaction->payment_state);
        }
        $texts = $services->texts();
        if (!$allowed) {
            $services->repository()->log($transaction, TransactionRepository::LOG_WARNING, $texts->admin('The customer clicked Cancel on CMI\'s payment page, but the order has a payment on CMI or its status could not be checked (%status%). The order was not cancelled; if the customer wants to cancel, void or refund the payment.', ['%status%' => $check !== '' ? $check : 'unknown']));

            return;
        }
        $transaction->payment_state = PaymentState::CLIENT_CANCELLED;
        $transaction->next_check_at = 0;
        $services->repository()->save($transaction);
        $services->orders()->setStateIfIn($transaction, (int) Configuration::get('PS_OS_CANCELED'), [$settings->awaitingStateId(), (int) Configuration::get('PS_OS_ERROR')]);
        $services->repository()->log($transaction, TransactionRepository::LOG_INFO, $texts->admin('The customer cancelled the payment on CMI\'s payment page: the order was cancelled.'));
    }
}
