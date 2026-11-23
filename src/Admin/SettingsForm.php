<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Admin;

use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Payment\TransactionGuard;
use Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's configuration page.
 */
class SettingsForm
{
    public const SUBMIT = 'submitCmiSettings';
    private const DOMAIN = 'Modules.Cmi.Admin';

    /** "Status after capture" value meaning "my own status", and its fields (not saved as settings). */
    private const CUSTOM_STATE = 'custom';
    private const CUSTOM_STATE_NAME = 'CMI_CUSTOM_STATE_NAME';
    private const CUSTOM_STATE_COLOR = 'CMI_CUSTOM_STATE_COLOR';

    /** Secret fields: an empty value keeps the saved one (it is never sent back to the browser). */
    private const SECRETS = [Settings::STORE_KEY, Settings::API_PASSWORD];

    /** @var \Module */
    private $module;
    /** @var \Context */
    private $context;
    /** @var bool the last submission was refused: show what was typed, not what is saved */
    private $refused = false;

    public function __construct(\Module $module, \Context $context)
    {
        $this->module = $module;
        $this->context = $context;
    }

    /**
     * Validate and save the submitted settings. Nothing is saved when a value is invalid.
     *
     * @return string[] errors (empty when saved)
     */
    public function process(): array
    {
        $values = $this->submittedValues();
        $errors = $this->validate($values);
        if ($errors) {
            $this->refused = true;

            return $errors;
        }
        if ($values[Settings::CAPTURED_STATE] === self::CUSTOM_STATE) {
            $values[Settings::CAPTURED_STATE] = $this->saveCustomState($values[self::CUSTOM_STATE_NAME], $values[self::CUSTOM_STATE_COLOR]);
            if ($values[Settings::CAPTURED_STATE] <= 0) {
                $this->refused = true;

                return [$this->t('The custom order status could not be saved.')];
            }
        }
        unset($values[self::CUSTOM_STATE_NAME], $values[self::CUSTOM_STATE_COLOR]);
        foreach ($values as $key => $value) {
            if (in_array($key, self::SECRETS, true) && $value === '') {
                continue;
            }
            \Configuration::updateValue($key, $value);
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function submittedValues(): array
    {
        $values = [];
        foreach ([
            Settings::KIT, Settings::CONFIRMATION_MODE, Settings::PAYMENT_LANGUAGE,
            Settings::CLIENT_ID, Settings::ENVIRONMENT, Settings::GATEWAY_URL, Settings::STORE_KEY,
            Settings::API_URL, Settings::API_USERNAME, Settings::API_PASSWORD, Settings::OID_PREFIX,
        ] as $key) {
            $values[$key] = trim((string) \Tools::getValue($key, ''));
        }
        // Empty addresses take those of the chosen CMI platform.
        $platform = Settings::PLATFORM_URLS[$values[Settings::ENVIRONMENT]] ?? null;
        if ($platform !== null && $values[Settings::GATEWAY_URL] === '') {
            $values[Settings::GATEWAY_URL] = $platform['gateway'];
        }
        if ($platform !== null && $values[Settings::API_URL] === '' && $values[Settings::KIT] === Settings::KIT_COMPLETE) {
            $values[Settings::API_URL] = $platform['api'];
        }
        $values[Settings::AUTO_REDIRECT] = (int) (bool) \Tools::getValue(Settings::AUTO_REDIRECT);
        $values[Settings::DELETE_DATA_ON_UNINSTALL] = (int) (bool) \Tools::getValue(Settings::DELETE_DATA_ON_UNINSTALL);
        $captured = (string) \Tools::getValue(Settings::CAPTURED_STATE);
        $values[Settings::CAPTURED_STATE] = $captured === self::CUSTOM_STATE ? self::CUSTOM_STATE : (int) $captured;
        $values[self::CUSTOM_STATE_NAME] = trim(strip_tags((string) \Tools::getValue(self::CUSTOM_STATE_NAME, '')));
        $values[self::CUSTOM_STATE_COLOR] = trim((string) \Tools::getValue(self::CUSTOM_STATE_COLOR, ''));
        $values[Settings::OID_PREFIX] = TransactionGuard::sanitizeOidPrefix($values[Settings::OID_PREFIX]);
        foreach ([Settings::TITLE, Settings::DESCRIPTION] as $key) {
            $values[$key] = [];
            foreach (\Language::getLanguages(false) as $language) {
                $values[$key][(int) $language['id_lang']] = trim(strip_tags((string) \Tools::getValue($key . '_' . (int) $language['id_lang'], '')));
            }
        }

        return $values;
    }

    /** @return string[] */
    private function validate(array $values): array
    {
        $errors = [];
        if (!preg_match('/^[0-9A-Za-z]{4,32}$/', $values[Settings::CLIENT_ID])) {
            $errors[] = $this->t('Enter the Client ID of your CMI store (MAD).');
        }
        if (!in_array($values[Settings::ENVIRONMENT], [Settings::ENV_TEST, Settings::ENV_PRODUCTION], true)) {
            $errors[] = $this->t('Choose the CMI platform: test or production.');
        }
        if (!$this->isHttpsUrl($values[Settings::GATEWAY_URL])) {
            $errors[] = $this->t('The payment page URL must be a valid https:// address, for example https://payment.cmi.co.ma/fim/est3Dgate.');
        }
        $storeKey = $values[Settings::STORE_KEY] !== '' ? $values[Settings::STORE_KEY] : (string) \Configuration::get(Settings::STORE_KEY);
        if ($storeKey === '') {
            $errors[] = $this->t('Enter the store key (hash key) set in your CMI merchant center.');
        } elseif (!TransactionGuard::isValidStoreKey($storeKey)) {
            $errors[] = $this->t('The store key must not contain the word "SKS". Ask CMI for a new key.');
        }
        if (!in_array($values[Settings::KIT], [Settings::KIT_STANDARD, Settings::KIT_COMPLETE], true)) {
            $errors[] = $this->t('Choose a CMI kit.');
        }
        if (!in_array($values[Settings::CONFIRMATION_MODE], [Settings::MODE_AUTOMATIC, Settings::MODE_MANUAL], true)) {
            $errors[] = $this->t('Choose a confirmation mode.');
        }
        if (!in_array($values[Settings::PAYMENT_LANGUAGE], ['auto', 'fr', 'en', 'ar'], true)) {
            $errors[] = $this->t('Choose the payment page language.');
        }
        if ($values[Settings::KIT] === Settings::KIT_COMPLETE) {
            $apiPassword = $values[Settings::API_PASSWORD] !== '' ? $values[Settings::API_PASSWORD] : (string) \Configuration::get(Settings::API_PASSWORD);
            if (!$this->isHttpsUrl($values[Settings::API_URL]) || $values[Settings::API_USERNAME] === '' || $apiPassword === '') {
                $errors[] = $this->t('The Complete Kit needs the API credentials provided by CMI: API URL (https://), username and password.');
            } elseif (stripos($values[Settings::API_URL], 'est3Dgate') !== false) {
                $errors[] = $this->t('The CMI API URL is not the payment page URL: it usually ends with /fim/api.');
            }
        }
        if ($values[Settings::CAPTURED_STATE] === self::CUSTOM_STATE) {
            $name = $values[self::CUSTOM_STATE_NAME];
            if ($name === '' || \Tools::strlen($name) > 64 || !\Validate::isGenericName($name)) {
                $errors[] = $this->t('Enter the name of your custom order status (64 characters at most, without < > = { }).');
            }
            if ($values[self::CUSTOM_STATE_COLOR] !== '' && !\Validate::isColor($values[self::CUSTOM_STATE_COLOR])) {
                $errors[] = $this->t('The color of the custom order status must be like #32CD32.');
            }
        } else {
            $state = new \OrderState((int) $values[Settings::CAPTURED_STATE]);
            if (!\Validate::isLoadedObject($state) || $state->deleted || !$state->paid) {
                $errors[] = $this->t('Choose a "paid" order status for captured payments.');
            }
        }

        return $errors;
    }

    /**
     * Create (or rename) the order status the merchant defined for captured payments. It counts
     * as paid, gives an invoice and sends the "payment accepted" e-mail, like "Payment accepted".
     *
     * @return int its ID, or 0 when it could not be saved
     */
    private function saveCustomState(string $name, string $color): int
    {
        $state = new \OrderState((int) \Configuration::getGlobalValue(Settings::OS_CUSTOM_CAPTURED));
        if (!\Validate::isLoadedObject($state) || $state->deleted) {
            $state = new \OrderState();
            $state->module_name = $this->module->name;
            $state->unremovable = false;
            $state->hidden = false;
            $state->deleted = false;
            $state->delivery = false;
            $state->shipped = false;
        }
        foreach (\Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = $name;
            $state->template[(int) $language['id_lang']] = 'payment';
        }
        $state->color = $color !== '' ? $color : ($state->color ?: '#32CD32');
        $state->paid = true;
        $state->logable = true;
        $state->invoice = true;
        $state->pdf_invoice = true;
        $state->send_email = true;
        if (!$state->save()) {
            return 0;
        }
        \Configuration::updateGlobalValue(Settings::OS_CUSTOM_CAPTURED, (int) $state->id);

        return (int) $state->id;
    }

    public function render(): string
    {
        $idLang = (int) $this->context->language->id;
        $helper = new \HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->module->name;
        $helper->table = 'configuration';
        $helper->identifier = 'id_configuration';
        $helper->token = \Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->module->name . '&tab_module=' . $this->module->tab . '&module_name=' . $this->module->name;
        $helper->default_form_language = $idLang;
        $helper->allow_employee_form_lang = (int) \Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = self::SUBMIT;
        $helper->tpl_vars = [
            'fields_value' => $this->fieldValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $idLang,
        ];

        return $helper->generateForm($this->forms());
    }

    private function forms(): array
    {
        $yesNo = [
            ['id' => 'on', 'value' => 1, 'label' => $this->t('Yes')],
            ['id' => 'off', 'value' => 0, 'label' => $this->t('No')],
        ];
        $submit = ['title' => $this->t('Save'), 'name' => self::SUBMIT];
        $prefix = (string) \Configuration::get(Settings::OID_PREFIX);
        $cronUrl = $this->context->link->getModuleLink($this->module->name, 'cron', ['token' => (string) \Configuration::get(Settings::CRON_TOKEN)], true);

        return [
            ['form' => [
                'legend' => ['title' => $this->t('Checkout'), 'icon' => 'icon-credit-card'],
                'input' => [
                    ['type' => 'text', 'lang' => true, 'name' => Settings::TITLE, 'label' => $this->t('Payment title'),
                        'desc' => $this->t('Name of the payment method at checkout. Leave empty for "Pay securely by bank card with CMI".')],
                    ['type' => 'textarea', 'lang' => true, 'name' => Settings::DESCRIPTION, 'label' => $this->t('Payment description'),
                        'desc' => $this->t('Optional text shown below the title when the customer chooses CMI.')],
                ],
                'submit' => $submit,
            ]],
            ['form' => [
                'legend' => ['title' => $this->t('Payment setup'), 'icon' => 'icon-cogs'],
                'input' => [
                    ['type' => 'select', 'name' => Settings::KIT, 'label' => $this->t('CMI kit'),
                        'desc' => $this->t('Standard Kit: customers pay on CMI\'s page; you manage payments in the CMI back office. Complete Kit: you can also confirm, void, refund and check payments from the order page, and orders left awaiting payment are checked automatically. It needs the API credentials provided by CMI.'),
                        'options' => ['query' => [
                            ['id' => Settings::KIT_STANDARD, 'name' => $this->t('Standard Kit (payment page only)')],
                            ['id' => Settings::KIT_COMPLETE, 'name' => $this->t('Complete Kit (with API: confirm, void, refund, check)')],
                        ], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'select', 'name' => Settings::CONFIRMATION_MODE, 'label' => $this->t('Payment confirmation'),
                        'desc' => $this->t('Automatic: the payment is captured as soon as the customer pays. Manual: the payment is only pre-authorized; you confirm it later to capture the funds (from the order page with the Complete Kit, otherwise in the CMI back office).'),
                        'options' => ['query' => [
                            ['id' => Settings::MODE_AUTOMATIC, 'name' => $this->t('Automatic: capture immediately')],
                            ['id' => Settings::MODE_MANUAL, 'name' => $this->t('Manual: pre-authorize, confirm later')],
                        ], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'select', 'name' => Settings::CAPTURED_STATE, 'label' => $this->t('Order status after capture'),
                        'desc' => $this->t('Status given to an order once its payment is captured. Choose "Custom" to create your own status.'),
                        'options' => ['query' => $this->paidStates(), 'id' => 'id_order_state', 'name' => 'name']],
                    ['type' => 'text', 'name' => self::CUSTOM_STATE_NAME, 'label' => $this->t('Custom status name'), 'maxlength' => 64, 'class' => 'fixed-width-xxl cmi-custom-state',
                        'desc' => $this->t('Name of your status, for example "Paid by card (CMI)". It counts as paid, gives an invoice and sends the "payment accepted" e-mail. You can rename it here later.')],
                    ['type' => 'color', 'name' => self::CUSTOM_STATE_COLOR, 'label' => $this->t('Custom status color'), 'class' => 'cmi-custom-state',
                        'desc' => $this->t('Color of the status in the orders list.')],
                    ['type' => 'switch', 'name' => Settings::AUTO_REDIRECT, 'label' => $this->t('Automatic redirect'), 'is_bool' => true, 'values' => $yesNo,
                        'desc' => $this->t('Send customers back to the shop as soon as the payment is finished. When off, they stay on CMI\'s result page and come back themselves.')],
                    ['type' => 'select', 'name' => Settings::PAYMENT_LANGUAGE, 'label' => $this->t('Payment page language'),
                        'options' => ['query' => [
                            ['id' => 'auto', 'name' => $this->t('Customer\'s language')],
                            ['id' => 'fr', 'name' => $this->t('French')],
                            ['id' => 'en', 'name' => $this->t('English')],
                            ['id' => 'ar', 'name' => $this->t('Arabic')],
                        ], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'text', 'name' => Settings::OID_PREFIX, 'label' => $this->t('Order ID prefix'), 'maxlength' => 20, 'class' => 'fixed-width-xl',
                        'desc' => $this->t('Orders appear in the CMI back office as this prefix followed by the order reference, for example %example%. It keeps this shop\'s orders apart from any other website using the same CMI account; give each website a different prefix. Letters, digits, - and _ only. Orders already sent keep their ID.', [
                            '%example%' => TransactionGuard::applyOidPrefix($prefix, 'XKBKNABJK'),
                        ])],
                    ['type' => 'switch', 'name' => Settings::DELETE_DATA_ON_UNINSTALL, 'label' => $this->t('Delete all CMI data on uninstall'), 'is_bool' => true, 'values' => $yesNo,
                        'desc' => $this->t('No (recommended): uninstalling keeps the CMI payment history of your orders and the module\'s order statuses, so they are found again if you reinstall the module. Yes: uninstalling also deletes the payment history, the order statuses and every CMI setting. This cannot be undone.')],
                ],
                'submit' => $submit,
            ]],
            ['form' => [
                'legend' => ['title' => $this->t('Connection to CMI'), 'icon' => 'icon-link'],
                'input' => [
                    ['type' => 'text', 'name' => Settings::CLIENT_ID, 'label' => $this->t('Client ID'), 'class' => 'fixed-width-xl',
                        'desc' => $this->t('Merchant Client ID provided by CMI, for example 600XXXXXX. Payments are made in MAD; orders in other currencies are converted to MAD with the exchange rates of International > Localization > Currencies.')],
                    ['type' => 'select', 'name' => Settings::ENVIRONMENT, 'label' => $this->t('CMI platform'),
                        'desc' => $this->t('Test: payments go to CMI\'s test platform, with the test credentials and test cards CMI gave you; no card is charged. Production: real payments. Choosing a platform fills in its addresses below.'),
                        'options' => ['query' => [
                            ['id' => Settings::ENV_TEST, 'name' => $this->t('Test (no real payment)')],
                            ['id' => Settings::ENV_PRODUCTION, 'name' => $this->t('Production (real payments)')],
                        ], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'text', 'name' => Settings::GATEWAY_URL, 'label' => $this->t('Payment page URL'),
                        'desc' => $this->t('CMI\'s payment page: %production% (production) or %test% (test). Change it only if CMI gives you another address.', [
                            '%production%' => Settings::PLATFORM_URLS[Settings::ENV_PRODUCTION]['gateway'],
                            '%test%' => Settings::PLATFORM_URLS[Settings::ENV_TEST]['gateway'],
                        ])],
                    ['type' => 'password', 'name' => Settings::STORE_KEY, 'label' => $this->t('Store key (hash key)'), 'class' => 'cmi-secret',
                        'desc' => $this->secretDescription(Settings::STORE_KEY, $this->t('Secret key set in your CMI merchant center. It must not contain the word "SKS".'))],
                ],
                'submit' => $submit,
            ]],
            ['form' => [
                'legend' => ['title' => $this->t('API credentials (Complete Kit)'), 'icon' => 'icon-key'],
                'input' => [
                    ['type' => 'text', 'name' => Settings::API_URL, 'label' => $this->t('CMI API URL'),
                        'desc' => $this->t('XML API address provided by CMI, for example https://payment.cmi.co.ma/fim/api. It is not the payment page URL.')],
                    ['type' => 'text', 'name' => Settings::API_USERNAME, 'label' => $this->t('API username'), 'class' => 'fixed-width-xxl'],
                    ['type' => 'password', 'name' => Settings::API_PASSWORD, 'label' => $this->t('API password'), 'class' => 'cmi-secret',
                        'desc' => $this->secretDescription(Settings::API_PASSWORD, '')],
                    ['type' => 'html', 'name' => 'cmi_cron', 'label' => $this->t('Automatic checks'),
                        'html_content' => '<p class="help-block">' . htmlspecialchars($this->t('With the Complete Kit, orders left awaiting payment are checked with CMI about 15 minutes, 1 h 15 and 7 h 15 after the customer was sent to CMI. Ask your host to call this address every 5 minutes (cron job), or use the "Cron tasks manager" module:'), ENT_QUOTES, 'UTF-8') . '</p>'
                            . '<input type="text" class="form-control cmi-select-all" readonly value="' . htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8') . '">'],
                ],
                'submit' => $submit,
            ]],
        ];
    }

    private function fieldValues(): array
    {
        $values = [];
        foreach ([
            Settings::KIT, Settings::CONFIRMATION_MODE, Settings::PAYMENT_LANGUAGE,
            Settings::CLIENT_ID, Settings::GATEWAY_URL, Settings::API_URL,
            Settings::API_USERNAME, Settings::OID_PREFIX, Settings::AUTO_REDIRECT, Settings::CAPTURED_STATE,
            Settings::DELETE_DATA_ON_UNINSTALL,
        ] as $key) {
            $values[$key] = $this->refused ? \Tools::getValue($key, \Configuration::get($key)) : \Configuration::get($key);
        }
        $values[Settings::ENVIRONMENT] = $this->refused ? \Tools::getValue(Settings::ENVIRONMENT) : (new Settings())->environment();
        // Secrets are never written into the page.
        $values[Settings::STORE_KEY] = '';
        $values[Settings::API_PASSWORD] = '';
        // The custom status is shown as "Custom", with its name and color ready to edit.
        $custom = $this->customState();
        if ($custom && (int) $values[Settings::CAPTURED_STATE] === (int) $custom->id) {
            $values[Settings::CAPTURED_STATE] = self::CUSTOM_STATE;
        }
        $idLang = (int) $this->context->language->id;
        $values[self::CUSTOM_STATE_NAME] = $this->refused
            ? \Tools::getValue(self::CUSTOM_STATE_NAME, '')
            : ($custom ? (string) ($custom->name[$idLang] ?? reset($custom->name)) : '');
        $values[self::CUSTOM_STATE_COLOR] = $this->refused
            ? \Tools::getValue(self::CUSTOM_STATE_COLOR, '#32CD32')
            : ($custom ? (string) $custom->color : '#32CD32');
        foreach ([Settings::TITLE, Settings::DESCRIPTION] as $key) {
            foreach (\Language::getLanguages(false) as $language) {
                $idLang = (int) $language['id_lang'];
                $values[$key][$idLang] = $this->refused ? \Tools::getValue($key . '_' . $idLang, \Configuration::get($key, $idLang)) : \Configuration::get($key, $idLang);
            }
        }
        $values['cmi_cron'] = '';

        return $values;
    }

    /** @return array<int, array{id_order_state: int|string, name: string}> the "paid" states, then "Custom" */
    private function paidStates(): array
    {
        $custom = $this->customState();
        $states = [];
        foreach (\OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            if ((int) $state['paid'] === 1 && empty($state['deleted']) && (!$custom || (int) $state['id_order_state'] !== (int) $custom->id)) {
                $states[] = ['id_order_state' => (int) $state['id_order_state'], 'name' => (string) $state['name']];
            }
        }
        $states[] = [
            'id_order_state' => self::CUSTOM_STATE,
            'name' => $custom
                ? $this->t('Custom: %name%', ['%name%' => (string) ($custom->name[(int) $this->context->language->id] ?? reset($custom->name))])
                : $this->t('Custom: create my own status...'),
        ];

        return $states;
    }

    /** The order status created through "Custom", if any. */
    private function customState(): ?\OrderState
    {
        $id = (int) \Configuration::getGlobalValue(Settings::OS_CUSTOM_CAPTURED);
        if ($id <= 0) {
            return null;
        }
        $state = new \OrderState($id);

        return \Validate::isLoadedObject($state) && !$state->deleted ? $state : null;
    }

    private function secretDescription(string $key, string $description): string
    {
        $saved = (string) \Configuration::get($key) !== '';

        return trim($description . ' ' . ($saved ? $this->t('A value is saved: leave the field empty to keep it.') : ''));
    }

    private function isHttpsUrl(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL) && stripos($url, 'https://') === 0;
    }

    private function t(string $text, array $parameters = []): string
    {
        return $this->module->getTranslator()->trans($text, $parameters, self::DOMAIN);
    }
}
