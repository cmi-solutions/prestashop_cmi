<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use Cmi\PrestaShop\Front\CmiEndpoint;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Protocol\CmiProtocol;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The customer comes back from CMI (okUrl / failUrl, a signed POST), then sees the result:
 * the order confirmation when paid or authorized, otherwise this page with "Pay again".
 * With the server callback (the default), this page only shows the result; it updates the
 * payment only in "Browser return only" mode, where CMI does not call the shop.
 *
 * The POST carries no session cookie (it comes from CMI's site), so it is only processed and
 * redirected; the page itself is shown on the following GET.
 *
 * @property Cmi $module
 */
class CmiReturnModuleFrontController extends ModuleFrontController
{
    use CmiEndpoint;

    public $ssl = true;

    public function postProcess()
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
            $this->processReturn();
        }
    }

    private function processReturn(): void
    {
        $services = $this->module->services();
        $fields = $this->postFields();
        $transaction = null;
        try {
            // In "Server to server" mode this only verifies the signature and finds the order:
            // the payment is updated by CMI's callback, never by the customer's browser.
            $result = $services->callbacks()->handle($fields, false, (int) $this->context->shop->id);
            $transaction = $result['valid'] ? $result['transaction'] : null;
        } catch (Throwable $e) {
            // The result page still shows the saved state; the server callback updates the order.
            PrestaShopLogger::addLog('CMI browser return failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 3, null, null, null, true);
            // Only a result signed by CMI may lead to the order's page (its link holds the order key).
            if (CmiProtocol::isValidCallback($fields, $services->settings()->storeKey())) {
                $oid = CmiProtocol::sanitizeOid((string) ($fields['oid'] ?? ''));
                $transaction = $oid !== '' ? $services->repository()->findByOid($oid, (int) $this->context->shop->id) : null;
            }
        }
        $order = $transaction ? new Order((int) $transaction->id_order) : null;
        if (!$order || !Validate::isLoadedObject($order)) {
            $this->errors[] = $this->trans('Your payment result could not be read. If you paid, your order will be updated shortly; otherwise please contact the shop.', [], 'Modules.Cmi.Shop');
            $this->redirectWithNotifications($this->context->link->getPageLink('index', true));
        }
        // What CMI told the cardholder, signed: only used to word the page while CMI's callback is on its way.
        $outcome = (string) ($fields['ProcReturnCode'] ?? '') === '00' ? 'approved' : 'declined';
        Tools::redirect($this->context->link->getModuleLink('cmi', 'return', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key, 'outcome' => $outcome], true));
    }

    public function initContent()
    {
        parent::initContent();
        $order = new Order((int) Tools::getValue('id_order'));
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name
            || !hash_equals((string) $order->secure_key, (string) Tools::getValue('key'))) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }
        $transaction = $this->module->services()->repository()->findByOrder($order);
        if (!$transaction) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }
        $state = (string) $transaction->payment_state;
        if (in_array($state, [PaymentState::CONFIRMED, PaymentState::PRE_CONFIRMATION_REQUIRED], true)) {
            Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
                'id_cart' => (int) $order->id_cart,
                'id_module' => (int) $this->module->id,
                'id_order' => (int) $order->id,
                'key' => (string) $order->secure_key,
            ]));
        }
        $this->context->smarty->assign($this->module->customerResult($transaction, $order, (string) Tools::getValue('outcome') === 'approved'));
        $this->context->smarty->assign([
            'cmi_refresh_url' => $this->context->link->getModuleLink('cmi', 'return', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true),
            'cmi_reference' => (string) $order->reference,
            'cmi_history_url' => $this->context->link->getPageLink('history', true),
            'cmi_contact_url' => $this->context->link->getPageLink('contact', true),
        ]);
        $this->setTemplate('module:cmi/views/templates/front/result.tpl');
    }
}
