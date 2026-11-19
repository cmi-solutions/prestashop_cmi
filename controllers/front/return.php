<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use Cmi\PrestaShop\Front\CmiEndpoint;
use Cmi\PrestaShop\Payment\PaymentState;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The customer comes back from CMI (okUrl / failUrl, a signed POST), then sees the result:
 * the order confirmation when paid or authorized, otherwise this page with "Pay again".
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
        $transaction = null;
        try {
            $result = $this->module->services()->callbacks()->handle($this->postFields(), false, (int) $this->context->shop->id);
            $transaction = $result['valid'] ? $result['transaction'] : null;
        } catch (Throwable $e) {
            // The result page still shows the saved state; the server callback updates the order.
            PrestaShopLogger::addLog('CMI browser return failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 3, null, null, null, true);
            $oid = (string) ($_POST['oid'] ?? '');
            $transaction = $oid !== '' ? $this->module->services()->repository()->findByOid(preg_replace('/[^A-Za-z0-9_-]/', '', $oid), (int) $this->context->shop->id) : null;
        }
        $order = $transaction ? new Order((int) $transaction->id_order) : null;
        if (!$order || !Validate::isLoadedObject($order)) {
            $this->errors[] = $this->trans('Your payment result could not be read. If you paid, your order will be updated shortly; otherwise please contact the shop.', [], 'Modules.Cmi.Shop');
            $this->redirectWithNotifications($this->context->link->getPageLink('index', true));
        }
        Tools::redirect($this->context->link->getModuleLink('cmi', 'return', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true));
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
        $this->context->smarty->assign($this->module->customerResult($transaction, $order));
        $this->context->smarty->assign([
            'cmi_reference' => (string) $order->reference,
            'cmi_history_url' => $this->context->link->getPageLink('history', true),
            'cmi_contact_url' => $this->context->link->getPageLink('contact', true),
        ]);
        $this->setTemplate('module:cmi/views/templates/front/result.tpl');
    }
}
