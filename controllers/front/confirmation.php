<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Return address of version 1.x (server-to-server mode), kept for payments started before
 * the upgrade: sends the customer to the payment result page.
 */
class CmiConfirmationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart = new Cart((int) Tools::getValue('cart_id'));
        $order = Validate::isLoadedObject($cart) ? Order::getByCartId((int) $cart->id) : null;
        if ($order && Validate::isLoadedObject($order) && hash_equals((string) $order->secure_key, (string) Tools::getValue('secure_key'))) {
            Tools::redirect($this->context->link->getModuleLink('cmi', 'return', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true));
        }
        Tools::redirect($this->context->link->getPageLink('history', true));
    }
}
