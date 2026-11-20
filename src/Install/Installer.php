<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Install;

use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Payment\TransactionGuard;
use Configuration;
use Db;
use Language;
use OrderState;
use Tab;
use Validate;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Installs, upgrades and removes what the module needs: tables, order states, the hidden admin
 * controller, hooks and default settings. Every step can run again safely.
 */
class Installer
{
    public const ADMIN_CONTROLLER = 'AdminCmiPayment';

    public const HOOKS = [
        'paymentOptions',
        'displayPaymentReturn',
        'displayOrderDetail',
        'displayAdminOrderMain',
        'actionAdminControllerSetMedia',
        'actionOrderGridDefinitionModifier',
        'actionOrderGridQueryBuilderModifier',
        'displayRightColumn',
    ];

    /** Settings of version 1.x and how they map to 2.0 (null: removed). */
    private const LEGACY_KEYS = [
        'merchid' => Settings::CLIENT_ID,
        'merchid_offshore' => Settings::CLIENT_ID_OFFSHORE,
        'actionslk' => Settings::GATEWAY_URL,
        'secretkey' => Settings::STORE_KEY,
        'currency_store' => Settings::STORE_CURRENCIES,
        'confirmation_mode' => Settings::CONFIRMATION_MODE,
        'redirection_mode' => Settings::AUTO_REDIRECT,
        // Removed: payments always go through 3-D Secure (CMI refuses the others).
        'cardholder_authentication' => null,
        'callback_mode' => Settings::CALLBACK_MODE,
        'actionslkapi' => Settings::API_URL,
        'usernameapi' => Settings::API_USERNAME,
        'passwordapi' => Settings::API_PASSWORD,
        'BPI_TYPE_DISPLAY' => null,
    ];

    /** Files of version 1.x that no longer exist (an upgrade by zip does not delete them). */
    private const OBSOLETE_FILES = [
        'confirmOf.php', 'fr.php', 'config_fr.xml', 'logo.gif', 'translations/fr.php',
        'views/css/font-awesome.min.css', 'views/css/cmi.css',
        'views/fonts/fontawesome-webfont.eot', 'views/fonts/fontawesome-webfont.svg', 'views/fonts/fontawesome-webfont.ttf',
        'views/fonts/fontawesome-webfont.woff', 'views/fonts/fontawesome-webfont.woff2',
        'views/templates/admin/admin.tpl', 'views/templates/front/error_ps17.tpl', 'views/templates/front/payment.tpl',
        'views/templates/front/payment_return.tpl', 'views/templates/hook/confirmation.tpl',
    ];

    /** @var \Module */
    private $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function install(): bool
    {
        return $this->createTables()
            && $this->installOrderStates()
            && $this->installTab()
            && $this->registerHooks()
            && $this->installDefaults();
    }

    /** From version 1.x: everything install() does, plus moving the old settings. */
    public function upgradeFromLegacy(): bool
    {
        $this->migrateLegacySettings();
        // Old hook names first: some are aliases of hooks install() registers again.
        foreach (['paymentReturn', 'adminOrder', 'rightColumn'] as $hook) {
            if ($this->module->isRegisteredInHook($hook)) {
                $this->module->unregisterHook($hook);
            }
        }
        $ok = $this->install();
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'bpi_secuaccess`');
        $this->deleteObsoleteFiles();

        return $ok;
    }

    /**
     * Uninstall keeps the transaction tables and order states, so the history of paid orders is
     * never lost, and removes the connection settings (credentials).
     */
    public function uninstall(): bool
    {
        $idTab = (int) Tab::getIdFromClassName(self::ADMIN_CONTROLLER);
        if ($idTab) {
            $tab = new Tab($idTab);
            $tab->delete();
        }
        foreach (Settings::ALL_KEYS as $key) {
            if (!in_array($key, [Settings::OS_AWAITING, Settings::OS_PREAUTHORIZED, Settings::OS_CUSTOM_CAPTURED, Settings::OID_PREFIX], true)) {
                Configuration::deleteByName($key);
            }
        }

        return true;
    }

    public function createTables(): bool
    {
        $engine = _MYSQL_ENGINE_;
        $queries = [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cmi_transaction` (
                `id_cmi_transaction` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_cart` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `order_reference` VARCHAR(64) NOT NULL DEFAULT \'\',
                `oid` VARCHAR(64) NOT NULL,
                `payment_state` VARCHAR(32) NOT NULL DEFAULT \'\',
                `account` VARCHAR(16) NOT NULL DEFAULT \'inshore\',
                `client_id` VARCHAR(32) NOT NULL DEFAULT \'\',
                `currency_iso` CHAR(3) NOT NULL DEFAULT \'MAD\',
                `currency_num` CHAR(3) NOT NULL DEFAULT \'504\',
                `amount_charged` DECIMAL(20,2) NOT NULL DEFAULT 0,
                `order_currency` CHAR(3) NOT NULL DEFAULT \'\',
                `amount_order` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `rate` DECIMAL(20,10) NOT NULL DEFAULT 1,
                `refunded` DECIMAL(20,2) NOT NULL DEFAULT 0,
                `own_ids` TEXT NULL,
                `last_response` TEXT NULL,
                `trans_stat` VARCHAR(8) NOT NULL DEFAULT \'\',
                `legacy` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `sent_at` INT UNSIGNED NOT NULL DEFAULT 0,
                `preauth_at` INT UNSIGNED NOT NULL DEFAULT 0,
                `pending_checks` INT UNSIGNED NOT NULL DEFAULT 0,
                `next_check_at` INT UNSIGNED NOT NULL DEFAULT 0,
                `lock_until` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_cmi_transaction`),
                KEY `oid_shop` (`oid`, `id_shop`),
                KEY `id_cart` (`id_cart`),
                KEY `order_reference` (`order_reference`),
                KEY `next_check_at` (`next_check_at`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cmi_transaction_log` (
                `id_cmi_transaction_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_cmi_transaction` INT UNSIGNED NOT NULL,
                `level` VARCHAR(16) NOT NULL DEFAULT \'info\',
                `message` TEXT NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cmi_transaction_log`),
                KEY `id_cmi_transaction` (`id_cmi_transaction`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
        ];
        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    public function installOrderStates(): bool
    {
        // Version 1.x created "Waiting for CMI validation" without ever using it: it becomes
        // "Awaiting CMI payment", keeping its ID.
        $legacyId = (int) Configuration::getGlobalValue('BPI_ID_ORDERSTATE');
        if (!(int) Configuration::getGlobalValue(Settings::OS_AWAITING) && $legacyId) {
            Configuration::updateGlobalValue(Settings::OS_AWAITING, $legacyId);
        }
        Configuration::deleteByName('BPI_ID_ORDERSTATE');

        return $this->installOrderState(Settings::OS_AWAITING, [
            'en' => 'Awaiting CMI payment',
            'fr' => 'En attente de paiement CMI',
        ], '#4169E1', false)
            && $this->installOrderState(Settings::OS_PREAUTHORIZED, [
                'en' => 'CMI payment authorized (to confirm)',
                'fr' => 'Paiement CMI autorisé (à confirmer)',
            ], '#FF8C00', true);
    }

    /**
     * @param array{en: string, fr: string} $names
     * @param bool                          $logable whether the order counts as a valid sale
     */
    private function installOrderState(string $configKey, array $names, string $color, bool $logable): bool
    {
        $state = new OrderState((int) Configuration::getGlobalValue($configKey));
        if (!Validate::isLoadedObject($state) || $state->deleted) {
            $state = new OrderState();
        }
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = strpos((string) $language['iso_code'], 'fr') === 0 ? $names['fr'] : $names['en'];
        }
        $state->module_name = $this->module->name;
        $state->color = $color;
        $state->logable = $logable;
        $state->paid = false;
        $state->invoice = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->send_email = false;
        $state->hidden = false;
        $state->unremovable = true;
        $state->deleted = false;
        $state->pdf_invoice = false;
        $state->pdf_delivery = false;
        if (!$state->save()) {
            return false;
        }
        Configuration::updateGlobalValue($configKey, (int) $state->id);

        return true;
    }

    public function installTab(): bool
    {
        if (Tab::getIdFromClassName(self::ADMIN_CONTROLLER)) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = self::ADMIN_CONTROLLER;
        $tab->module = $this->module->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentOrders');
        // Not shown in the menu: it only runs the actions of the order page's CMI panel.
        $tab->active = false;
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'CMI';
        }

        return (bool) $tab->add();
    }

    public function registerHooks(): bool
    {
        foreach (self::HOOKS as $hook) {
            if (!$this->module->isRegisteredInHook($hook) && !$this->module->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    public function installDefaults(): bool
    {
        $defaults = [
            Settings::KIT => Settings::KIT_STANDARD,
            Settings::CONFIRMATION_MODE => Settings::MODE_AUTOMATIC,
            Settings::CALLBACK_MODE => Settings::CALLBACK_SERVER,
            Settings::AUTO_REDIRECT => '1',
            Settings::PAYMENT_LANGUAGE => 'auto',
            Settings::STORE_CURRENCIES => '504',
            Settings::CAPTURED_STATE => (string) (int) Configuration::get('PS_OS_PAYMENT'),
            // A unique prefix keeps this shop's OrderIds apart from any other website using the
            // same CMI account. Only set once: a prefix the merchant changed or emptied is kept.
            Settings::OID_PREFIX => TransactionGuard::generateOidPrefix(),
            Settings::CRON_TOKEN => bin2hex(random_bytes(16)),
        ];
        foreach ($defaults as $key => $value) {
            if (!Configuration::hasKey($key) && !Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /** Copy the 1.x settings to their 2.0 names, in every shop scope where they were saved. */
    public function migrateLegacySettings(): void
    {
        $names = array_map('pSQL', array_keys(self::LEGACY_KEYS));
        $rows = Db::getInstance()->executeS(
            'SELECT `name`, `value`, `id_shop_group`, `id_shop` FROM `' . _DB_PREFIX_ . 'configuration`'
            . ' WHERE `name` IN (\'' . implode('\',\'', $names) . '\')'
        ) ?: [];

        $hasApi = [];
        foreach ($rows as $row) {
            $target = self::LEGACY_KEYS[$row['name']] ?? null;
            if ($target === null) {
                continue;
            }
            $value = $this->legacyValue($row['name'], (string) $row['value']);
            $group = $row['id_shop_group'] !== null ? (int) $row['id_shop_group'] : null;
            $shop = $row['id_shop'] !== null ? (int) $row['id_shop'] : null;
            Configuration::updateValue($target, $value, false, $group, $shop);
            if (in_array($row['name'], ['actionslkapi', 'usernameapi', 'passwordapi'], true) && trim($value) !== '') {
                $scope = $group . '-' . $shop;
                $hasApi[$scope] = ($hasApi[$scope] ?? 0) + 1;
                if ($hasApi[$scope] === 3) {
                    // 1.x used the API whenever its credentials were set: keep doing so.
                    Configuration::updateValue(Settings::KIT, Settings::KIT_COMPLETE, false, $group, $shop);
                }
            }
        }
        foreach (array_keys(self::LEGACY_KEYS) as $legacy) {
            Configuration::deleteByName($legacy);
        }
    }

    private function legacyValue(string $legacyKey, string $value): string
    {
        $value = trim($value);
        switch ($legacyKey) {
            case 'confirmation_mode':
                return $value === '2' ? Settings::MODE_MANUAL : Settings::MODE_AUTOMATIC;
            case 'redirection_mode':
                return $value === '1' ? '1' : '0';
            case 'callback_mode':
                // 1 = "Redirection automatique": CMI only returned the result through the browser.
                return $value === '1' ? Settings::CALLBACK_BROWSER : Settings::CALLBACK_SERVER;
            case 'currency_store':
                return implode(',', array_filter(array_map('trim', explode('|', $value))));
        }

        return $value;
    }

    private function deleteObsoleteFiles(): void
    {
        $base = rtrim($this->module->getLocalPath(), '/\\') . '/';
        foreach (self::OBSOLETE_FILES as $file) {
            if (is_file($base . $file)) {
                @unlink($base . $file);
            }
        }
        // The fonts folder of 1.x, once empty (it may hold an index.php).
        $fonts = $base . 'views/fonts';
        if (is_dir($fonts) && count(array_diff((array) scandir($fonts), ['.', '..', 'index.php'])) === 0) {
            if (is_file($fonts . '/index.php')) {
                unlink($fonts . '/index.php');
            }
            rmdir($fonts);
        }
    }
}
