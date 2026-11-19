<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use Cmi\PrestaShop\Model\CmiTransaction;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Service\PaymentRequest;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The customer chose CMI at checkout (or "Pay now" on an unpaid order): create the order,
 * awaiting payment, and send the customer to CMI's payment page with a signed form.
 *
 * @property Cmi $module
 */
class CmiValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        if (!$this->module->active) {
            Tools::redirect($this->context->link->getPageLink('order', true));
        }
        if (Tools::getIsset('id_order')) {
            $this->payExistingOrder();

            return;
        }
        $this->payCart();
    }

    private function payCart(): void
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || !$cart->id_customer || !$cart->id_address_invoice || !$cart->nbProducts()) {
            Tools::redirect($this->context->link->getPageLink('order', true));
        }
        if (!hash_equals((string) Tools::getToken(false), (string) Tools::getValue('token'))) {
            $this->fail($this->trans('Your session has expired. Please choose the payment method again.', [], 'Modules.Cmi.Shop'));
        }
        if (!$this->isOffered()) {
            $this->fail($this->trans('This payment method is not available.', [], 'Modules.Cmi.Shop'));
        }
        $customer = new Customer((int) $cart->id_customer);
        $address = new Address((int) $cart->id_address_invoice);
        $currency = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($customer) || !Validate::isLoadedObject($address)) {
            Tools::redirect($this->context->link->getPageLink('order', true));
        }

        $request = $this->module->services()->paymentRequest();
        if (!$request->hasValidName($address)) {
            $this->fail($this->nameError());
        }
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $charge = $request->charge($currency, $total);
        if (!is_array($charge)) {
            $this->fail($this->chargeError($charge, (string) $currency->iso_code));
        }

        $this->module->validateOrder(
            (int) $cart->id,
            $this->module->services()->settings()->awaitingStateId(),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );
        $orders = $this->ordersOfCart((int) $cart->id);
        if (!$orders) {
            $this->fail($this->trans('Your order could not be created. Please try again.', [], 'Modules.Cmi.Shop'));
        }
        $transaction = $request->recordAttempt($orders, $charge);
        $this->sendToCmi($transaction, $customer, $address);
    }

    /** "Pay now" on an order whose payment failed or never came. */
    private function payExistingOrder(): void
    {
        $order = new Order((int) Tools::getValue('id_order'));
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name
            || !hash_equals((string) $order->secure_key, (string) Tools::getValue('key'))) {
            Tools::redirect($this->context->link->getPageLink('history', true));
        }
        $services = $this->module->services();
        $transaction = $services->repository()->findByOrder($order);
        $settings = $services->settings();
        $payable = [$settings->awaitingStateId(), (int) Configuration::get('PS_OS_ERROR')];
        if (!$transaction || !PaymentState::isAwaitingPayment((string) $transaction->payment_state)
            || !in_array((int) $order->getCurrentState(), $payable, true)) {
            Tools::redirect($this->context->link->getModuleLink('cmi', 'return', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true));
        }
        $orders = $services->orders()->ordersOf($transaction);
        $total = 0.0;
        foreach ($orders as $sibling) {
            $total += (float) $sibling->total_paid_tax_incl;
        }
        $currency = new Currency((int) $order->id_currency);
        $customer = new Customer((int) $order->id_customer);
        $address = new Address((int) $order->id_address_invoice);
        $request = $services->paymentRequest();
        if (!$request->hasValidName($address)) {
            $this->fail($this->nameError(), $order);
        }
        $charge = $request->charge($currency, $total);
        if (!is_array($charge)) {
            $this->fail($this->chargeError($charge, (string) $currency->iso_code), $order);
        }
        $services->orders()->setStateIfIn($transaction, $settings->awaitingStateId(), [(int) Configuration::get('PS_OS_ERROR')]);
        $transaction = $request->recordAttempt($orders, $charge, $transaction);
        $services->repository()->log($transaction, 'info', $services->texts()->admin('The customer tried to pay again.'));
        $this->sendToCmi($transaction, $customer, $address);
    }

    private function sendToCmi(CmiTransaction $transaction, Customer $customer, Address $address): void
    {
        $services = $this->module->services();
        $fields = $services->paymentRequest()->formFields($transaction, $customer, $address, (string) $this->context->language->iso_code, $this->context->link);
        $order = new Order((int) $transaction->id_order);
        $this->context->smarty->assign([
            'cmi_action' => $services->settings()->gatewayUrl(),
            'cmi_fields' => $fields,
            'cmi_signed' => implode(',', array_keys($fields)),
            'cmi_cancel_url' => $fields['shopurl'],
            'cmi_reference' => (string) $order->reference,
        ]);
        $this->setTemplate('module:cmi/views/templates/front/redirect.tpl');
    }

    /** Whether CMI is among the payment options PrestaShop allows for this cart. */
    private function isOffered(): bool
    {
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                return true;
            }
        }

        return false;
    }

    /** @return Order[] */
    private function ordersOfCart(int $idCart): array
    {
        $orders = [];
        $reference = (string) $this->module->currentOrderReference;
        foreach ($reference !== '' ? Order::getByReference($reference) : [] as $order) {
            if ((int) $order->id_cart === $idCart) {
                $orders[(int) $order->id] = $order;
            }
        }
        if (!$orders && ($order = Order::getByCartId($idCart)) && Validate::isLoadedObject($order)) {
            $orders[(int) $order->id] = $order;
        }
        ksort($orders);

        return array_values($orders);
    }

    private function nameError(): string
    {
        return $this->trans('To pay by card with CMI, please enter your first and last name in Latin letters in your invoice address (for example: Mohamed Alami).', [], 'Modules.Cmi.Shop');
    }

    private function chargeError(string $code, string $currency): string
    {
        if ($code === PaymentRequest::ERROR_CURRENCY || $code === PaymentRequest::ERROR_RATE) {
            return $this->trans('Card payment in %currency% is temporarily unavailable. Please choose another currency or contact the shop.', ['%currency%' => $currency], 'Modules.Cmi.Shop');
        }

        return $this->trans('This payment method is not available.', [], 'Modules.Cmi.Shop');
    }

    /** Back to the checkout (or the order's result page) with an error message. */
    private function fail(string $message, ?Order $order = null): void
    {
        $this->errors[] = $message;
        $this->redirectWithNotifications($order
            ? $this->context->link->getModuleLink('cmi', 'return', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true)
            : $this->context->link->getPageLink('order', true));
    }
}
