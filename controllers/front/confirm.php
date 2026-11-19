<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use Cmi\PrestaShop\Front\CmiEndpoint;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Address of version 1.x, kept for payments started before the upgrade: 1.x sent it as the
 * callback (server-to-server mode) or as the customer's return (browser return mode, where it
 * also sent CallbackResponse=false).
 *
 * @property Cmi $module
 */
class CmiConfirmModuleFrontController extends ModuleFrontController
{
    use CmiEndpoint;

    public $ssl = true;
    public $ajax = true;

    public function postProcess()
    {
        $fields = $this->postFields();
        $isServer = strtolower((string) ($fields['CallbackResponse'] ?? 'true')) !== 'false';
        try {
            $result = $this->module->services()->callbacks()->handle($fields, $isServer, (int) $this->context->shop->id);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('CMI callback failed: ' . $e->getMessage(), 3, null, null, null, true);
            $this->answer('ERROR', 500);

            return;
        }
        if ($isServer) {
            $this->answer($result['answer'], $result['valid'] ? 200 : 400);
        }
        $transaction = $result['transaction'];
        $order = $transaction ? new Order((int) $transaction->id_order) : null;
        if ($order && Validate::isLoadedObject($order)) {
            Tools::redirect($this->context->link->getModuleLink('cmi', 'return', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true));
        }
        Tools::redirect($this->context->link->getPageLink('index', true));
    }
}
