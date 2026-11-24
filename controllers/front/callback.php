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
 * Server-to-server callback (callbackUrl): CMI posts the payment result here and reads the
 * answer: ACTION=POSTAUTH captures the payment, APPROVED keeps it pre-authorized.
 *
 * @property Cmi $module
 */
class CmiCallbackModuleFrontController extends ModuleFrontController
{
    use CmiEndpoint;

    public $ssl = true;
    public $ajax = true;

    public function postProcess()
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            $this->answer('Method not allowed', 405);
        }
        try {
            $result = $this->module->services()->callbacks()->handle($this->postFields(), true, (int) $this->context->shop->id);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('CMI callback failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 3, null, null, null, true);
            $this->answer('ERROR', 500);

            return;
        }
        $this->answer($result['answer'], $result['valid'] ? 200 : 400);
    }
}
