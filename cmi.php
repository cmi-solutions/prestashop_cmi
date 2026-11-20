<?php
/**
 * CMI payment module for PrestaShop.
 *
 * Card payments on the hosted payment page of CMI (Centre Monétique Interbancaire, Morocco),
 * with payment management (confirm, void, refund, status check) from the order page.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

// Classes of src/ (namespace Cmi\PrestaShop), without requiring Composer on the shop.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Cmi\\PrestaShop\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Cmi\PrestaShop\Admin\OrderGrid;
use Cmi\PrestaShop\Admin\OrderPanel;
use Cmi\PrestaShop\Admin\SettingsForm;
use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Install\Installer;
use Cmi\PrestaShop\Payment\AccountResolver;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Services;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Cmi extends PaymentModule
{
    /** @var Services|null */
    private $services;

    public function __construct()
    {
        $this->name = 'cmi';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->author = 'CMI';
        $this->need_instance = 0;
        $this->bootstrap = true;
        // This branch (version_8) is the PrestaShop 8.x package; version_9 is the 9.x one.
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
        $this->controllers = ['validation', 'callback', 'return', 'cancel', 'cron'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->trans('CMI', [], 'Modules.Cmi.Admin');
        $this->description = $this->trans('Card payments with CMI (Morocco): hosted payment page, and confirm, void, refund and check payments from the order page.', [], 'Modules.Cmi.Admin');
        $this->confirmUninstall = $this->trans('Uninstall the CMI module? Your CMI connection settings will be deleted; orders and their payment history are kept.', [], 'Modules.Cmi.Admin');
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function install()
    {
        if (!extension_loaded('curl')) {
            $this->_errors[] = $this->trans('The PHP cURL extension is required.', [], 'Modules.Cmi.Admin');

            return false;
        }

        return parent::install() && (new Installer($this))->install();
    }

    public function uninstall()
    {
        return (new Installer($this))->uninstall() && parent::uninstall();
    }

    public function services(): Services
    {
        if ($this->services === null) {
            $this->services = new Services($this);
        }

        return $this->services;
    }

    // ── Configuration page ───────────────────────────────────────────────────

    public function getContent()
    {
        $form = new SettingsForm($this, $this->context);
        $output = '';
        if (Tools::isSubmit(SettingsForm::SUBMIT)) {
            $errors = $form->process();
            $output .= $errors
                ? $this->displayError(implode('<br>', array_map(static function (string $error): string {
                    return htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
                }, $errors)))
                : $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Cmi.Admin'));
        }
        foreach ($this->configurationWarnings() as $warning) {
            $output .= $this->displayWarning(htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'));
        }

        return $output . $form->render();
    }

    /** @return string[] */
    private function configurationWarnings(): array
    {
        $settings = new Settings();
        $warnings = [];
        // CMI is hidden at checkout until the connection is complete: say what is missing.
        if (!$settings->isPaymentConfigured()) {
            $missing = [];
            if ($settings->get(Settings::CLIENT_ID) === '' && $settings->get(Settings::CLIENT_ID_OFFSHORE) === '') {
                $missing[] = $this->trans('the Client ID', [], 'Modules.Cmi.Admin');
            }
            if ($settings->storeKey() === '') {
                $missing[] = $this->trans('the store key (hash key)', [], 'Modules.Cmi.Admin');
            }
            if ($settings->gatewayUrl() === '') {
                $missing[] = $this->trans('the payment page URL', [], 'Modules.Cmi.Admin');
            }
            $warnings[] = $this->trans('CMI is not offered to customers at checkout yet: enter %missing% in "Connection to CMI", then save.', ['%missing%' => implode(', ', $missing)], 'Modules.Cmi.Admin');
        }
        if (in_array(AccountResolver::MAD_NUMERIC, $settings->storeCurrencies(), true) && !Currency::getIdByIsoCode(AccountResolver::MAD)) {
            $warnings[] = $this->trans('The Moroccan dirham (MAD) is not installed in Localization > Currencies: orders in other currencies cannot be converted, so CMI is not offered for them.', [], 'Modules.Cmi.Admin');
        }
        if (!Configuration::get('PS_SSL_ENABLED')) {
            $warnings[] = $this->trans('HTTPS is not enabled on this shop. CMI requires https:// addresses for the payment return and callback.', [], 'Modules.Cmi.Admin');
        }
        if (Configuration::get(Settings::KIT) === Settings::KIT_COMPLETE && !$settings->hasApiCredentials()) {
            $warnings[] = $this->trans('The Complete Kit is selected but the API credentials are missing: the module works like the Standard Kit until they are entered.', [], 'Modules.Cmi.Admin');
        }

        return $warnings;
    }

    // ── Checkout ─────────────────────────────────────────────────────────────

    public function hookPaymentOptions($params)
    {
        if (!$this->active || empty($params['cart']) || !$this->checkCurrency($params['cart'])) {
            return [];
        }
        /** @var Cart $cart */
        $cart = $params['cart'];
        $charge = $this->services()->paymentRequest()->charge(new Currency((int) $cart->id_currency), (float) $cart->getOrderTotal(true, Cart::BOTH));
        if (!is_array($charge)) {
            return [];
        }
        $settings = $this->services()->settings();
        $idLang = (int) $this->context->language->id;
        $title = $settings->getLang(Settings::TITLE, $idLang);
        $description = $settings->getLang(Settings::DESCRIPTION, $idLang);
        $this->context->smarty->assign([
            'cmi_description' => $description,
            'cmi_converted' => $charge['order_currency'] !== $charge['currency_iso'],
            'cmi_charged' => $this->context->getCurrentLocale()->formatPrice($charge['amount_charged'], $charge['currency_iso']),
            'cmi_charged_currency' => $charge['currency_iso'],
        ]);

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($title !== '' ? $title : $this->trans('Pay securely by bank card with CMI', [], 'Modules.Cmi.Shop'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setInputs(['token' => ['name' => 'token', 'type' => 'hidden', 'value' => Tools::getToken(false)]])
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/cb.png'))
            ->setAdditionalInformation($this->fetch('module:cmi/views/templates/hook/payment_option.tpl'));

        return [$option];
    }

    public function checkCurrency($cart)
    {
        $currency = new Currency((int) $cart->id_currency);
        foreach ((array) $this->getCurrency((int) $cart->id_currency) as $allowed) {
            if ((int) $currency->id === (int) $allowed['id_currency']) {
                return true;
            }
        }

        return false;
    }

    /** Order confirmation page: the payment's result in plain words. */
    public function hookDisplayPaymentReturn($params)
    {
        $order = $params['order'] ?? null;
        if (!$this->active || !$order instanceof Order || $order->module !== $this->name) {
            return '';
        }
        $transaction = $this->services()->repository()->findByOrder($order);
        if (!$transaction) {
            return '';
        }
        $this->context->smarty->assign($this->customerResult($transaction, $order));

        return $this->fetch('module:cmi/views/templates/hook/payment_result.tpl');
    }

    /** Customer account, order details: the payment's result, and "Pay now" when still unpaid. */
    public function hookDisplayOrderDetail($params)
    {
        $order = $params['order'] ?? null;
        if (!$this->active || !$order instanceof Order || $order->module !== $this->name) {
            return '';
        }
        $transaction = $this->services()->repository()->findByOrder($order);
        if (!$transaction) {
            return '';
        }
        $this->context->smarty->assign($this->customerResult($transaction, $order));

        return $this->fetch('module:cmi/views/templates/hook/payment_result.tpl');
    }

    /** @return array<string, mixed> */
    public function customerResult(Cmi\PrestaShop\Model\CmiTransaction $transaction, Order $order): array
    {
        $texts = [
            PaymentState::CONFIRMED => [$this->trans('Payment successful', [], 'Modules.Cmi.Shop'), $this->trans('Your card payment was accepted.', [], 'Modules.Cmi.Shop')],
            PaymentState::PRE_CONFIRMATION_REQUIRED => [$this->trans('Payment authorized', [], 'Modules.Cmi.Shop'), $this->trans('Your payment was authorized. The amount will be charged when the shop confirms your order.', [], 'Modules.Cmi.Shop')],
            PaymentState::VOIDED => [$this->trans('Payment cancelled', [], 'Modules.Cmi.Shop'), $this->trans('The shop cancelled this payment. You were not charged.', [], 'Modules.Cmi.Shop')],
            PaymentState::CLIENT_CANCELLED => [$this->trans('Payment cancelled', [], 'Modules.Cmi.Shop'), $this->trans('You cancelled the payment.', [], 'Modules.Cmi.Shop')],
            PaymentState::REFUNDED => [$this->trans('Payment refunded', [], 'Modules.Cmi.Shop'), $this->trans('This payment was refunded.', [], 'Modules.Cmi.Shop')],
            PaymentState::FAILED => [$this->trans('Payment not completed', [], 'Modules.Cmi.Shop'), $this->trans('The card payment was not accepted. You can try again.', [], 'Modules.Cmi.Shop')],
            PaymentState::INITIATED => [$this->trans('Awaiting payment', [], 'Modules.Cmi.Shop'), $this->trans('We have not received the result of your card payment yet.', [], 'Modules.Cmi.Shop')],
        ];
        $state = (string) $transaction->payment_state;
        [$label, $message] = $texts[$state] ?? $texts[PaymentState::INITIATED];
        $retry = PaymentState::isAwaitingPayment($state)
            && in_array((int) $order->getCurrentState(), [$this->services()->settings()->awaitingStateId(), (int) Configuration::get('PS_OS_ERROR')], true);

        return [
            'cmi_state' => $state,
            'cmi_label' => $label,
            'cmi_message' => $message,
            'cmi_oid' => (string) $transaction->oid,
            'cmi_retry_url' => $retry ? $this->context->link->getModuleLink($this->name, 'validation', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true) : '',
        ];
    }

    // ── Back office ──────────────────────────────────────────────────────────

    public function hookDisplayAdminOrderMain($params)
    {
        $order = new Order((int) ($params['id_order'] ?? 0));
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }
        $variables = (new OrderPanel($this, $this->services(), $this->context))->variables($order);
        if ($variables === null) {
            return '';
        }
        $this->context->smarty->assign(['cmi' => $variables]);

        return $this->fetch('module:cmi/views/templates/admin/order_panel.tpl');
    }

    public function hookActionAdminControllerSetMedia()
    {
        $controller = $this->context->controller;
        $name = isset($controller->controller_name) ? (string) $controller->controller_name : (string) Tools::getValue('controller');
        if ($name === 'AdminOrders') {
            $controller->addCSS($this->_path . 'views/css/admin.css');
            $controller->addJS($this->_path . 'views/js/admin-order.js');
        } elseif ($name === 'AdminModules' && Tools::getValue('configure') === $this->name) {
            $controller->addCSS($this->_path . 'views/css/admin.css');
            $controller->addJS($this->_path . 'views/js/admin-settings.js');
        }
    }

    public function hookActionOrderGridDefinitionModifier($params)
    {
        if (isset($params['definition'])) {
            OrderGrid::modifyDefinition($params['definition'], $this);
        }
    }

    public function hookActionOrderGridQueryBuilderModifier($params)
    {
        if (isset($params['search_query_builder'])) {
            OrderGrid::modifyQuery($params['search_query_builder'], $this);
        }
    }

    // ── Front office ─────────────────────────────────────────────────────────

    /** CMI logo in the side column (kept from version 1.x). */
    public function hookDisplayRightColumn($params)
    {
        if (!$this->active) {
            return '';
        }
        $this->context->smarty->assign('cmi_logo', Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/logo_cmi.png'));

        return $this->fetch('module:cmi/views/templates/front/logo.tpl');
    }

    public function hookDisplayLeftColumn($params)
    {
        return $this->hookDisplayRightColumn($params);
    }
}
