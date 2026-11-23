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
 * Runs the automatic status checks that are due (Complete Kit). Called by a cron job with the
 * secret token shown on the configuration page. In a multistore, call each shop's address.
 *
 * @property Cmi $module
 */
class CmiCronModuleFrontController extends ModuleFrontController
{
    use CmiEndpoint;

    public $ssl = true;
    public $ajax = true;

    public function postProcess()
    {
        $token = $this->module->services()->settings()->cronToken();
        if ($token === '' || !hash_equals($token, (string) Tools::getValue('token'))) {
            $this->answer('Forbidden', 403);
        }
        $checked = $this->module->services()->pendingChecker()->runDue((int) $this->context->shop->id);
        $this->answer('OK ' . $checked);
    }
}
