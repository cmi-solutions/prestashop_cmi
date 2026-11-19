<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Config;

use Cmi\PrestaShop\Payment\AccountResolver;
use Cmi\PrestaShop\Payment\TransactionGuard;
use Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's settings, read from PrestaShop's configuration (per shop in multistore).
 */
final class Settings
{
    public const CLIENT_ID = 'CMI_CLIENT_ID';
    public const CLIENT_ID_OFFSHORE = 'CMI_CLIENT_ID_OFFSHORE';
    public const GATEWAY_URL = 'CMI_GATEWAY_URL';
    public const STORE_KEY = 'CMI_STORE_KEY';
    public const STORE_CURRENCIES = 'CMI_STORE_CURRENCIES';
    public const KIT = 'CMI_KIT';
    public const API_URL = 'CMI_API_URL';
    public const API_USERNAME = 'CMI_API_USERNAME';
    public const API_PASSWORD = 'CMI_API_PASSWORD';
    public const CONFIRMATION_MODE = 'CMI_CONFIRMATION_MODE';
    public const CALLBACK_MODE = 'CMI_CALLBACK_MODE';
    public const THREE_D_SECURE = 'CMI_3D_SECURE';
    public const AUTO_REDIRECT = 'CMI_AUTO_REDIRECT';
    public const PAYMENT_LANGUAGE = 'CMI_PAYMENT_LANGUAGE';
    public const OID_PREFIX = 'CMI_OID_PREFIX';
    public const CAPTURED_STATE = 'CMI_CAPTURED_STATE';
    public const TITLE = 'CMI_TITLE';
    public const DESCRIPTION = 'CMI_DESCRIPTION';
    public const CRON_TOKEN = 'CMI_CRON_TOKEN';

    /** Order states installed by the module. */
    public const OS_AWAITING = 'CMI_OS_AWAITING';
    public const OS_PREAUTHORIZED = 'CMI_OS_PREAUTHORIZED';

    public const KIT_STANDARD = 'standard';
    public const KIT_COMPLETE = 'complete';
    public const MODE_AUTOMATIC = 'automatic';
    public const MODE_MANUAL = 'manual';
    /** CMI calls the shop server to server (recommended). */
    public const CALLBACK_SERVER = 'server';
    /** CMI only sends the result through the customer's browser (shops CMI's servers cannot reach). */
    public const CALLBACK_BROWSER = 'browser';

    /** Every key the module writes, removed on uninstall. */
    public const ALL_KEYS = [
        self::CLIENT_ID, self::CLIENT_ID_OFFSHORE, self::GATEWAY_URL, self::STORE_KEY, self::STORE_CURRENCIES,
        self::KIT, self::API_URL, self::API_USERNAME, self::API_PASSWORD, self::CONFIRMATION_MODE,
        self::CALLBACK_MODE, self::THREE_D_SECURE, self::AUTO_REDIRECT, self::PAYMENT_LANGUAGE, self::OID_PREFIX,
        self::CAPTURED_STATE, self::TITLE, self::DESCRIPTION, self::CRON_TOKEN, self::OS_AWAITING, self::OS_PREAUTHORIZED,
    ];

    /** @var int|null */
    private $idShop;

    public function __construct(?int $idShop = null)
    {
        $this->idShop = $idShop;
    }

    public function get(string $key): string
    {
        $value = $this->idShop === null
            ? Configuration::get($key)
            : Configuration::get($key, null, null, $this->idShop);

        return $value === false || $value === null ? '' : trim((string) $value);
    }

    public function getLang(string $key, int $idLang): string
    {
        $value = Configuration::get($key, $idLang, null, $this->idShop);

        return $value === false || $value === null ? '' : trim((string) $value);
    }

    public function clientId(string $account): string
    {
        return $this->get($account === AccountResolver::OFFSHORE ? self::CLIENT_ID_OFFSHORE : self::CLIENT_ID);
    }

    public function gatewayUrl(): string
    {
        return $this->get(self::GATEWAY_URL);
    }

    public function storeKey(): string
    {
        return $this->get(self::STORE_KEY);
    }

    /** @return string[] ISO 4217 numeric codes */
    public function storeCurrencies(): array
    {
        $raw = $this->get(self::STORE_CURRENCIES);
        $codes = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $raw) ?: [])));

        return $codes ?: [AccountResolver::MAD_NUMERIC];
    }

    public function confirmationMode(): string
    {
        return $this->get(self::CONFIRMATION_MODE) === self::MODE_MANUAL ? self::MODE_MANUAL : self::MODE_AUTOMATIC;
    }

    public function callbackMode(): string
    {
        return $this->get(self::CALLBACK_MODE) === self::CALLBACK_BROWSER ? self::CALLBACK_BROWSER : self::CALLBACK_SERVER;
    }

    public function isThreeDSecure(): bool
    {
        return $this->get(self::THREE_D_SECURE) !== '0';
    }

    public function isAutoRedirect(): bool
    {
        return $this->get(self::AUTO_REDIRECT) !== '0';
    }

    public function paymentLanguage(string $shopLanguage): string
    {
        $setting = $this->get(self::PAYMENT_LANGUAGE);
        if (in_array($setting, ['fr', 'en', 'ar'], true)) {
            return $setting;
        }
        $shopLanguage = strtolower(substr($shopLanguage, 0, 2));

        return in_array($shopLanguage, ['fr', 'en', 'ar'], true) ? $shopLanguage : 'en';
    }

    public function oidPrefix(): string
    {
        return TransactionGuard::sanitizeOidPrefix($this->get(self::OID_PREFIX));
    }

    public function capturedStateId(): int
    {
        $id = (int) $this->get(self::CAPTURED_STATE);

        return $id > 0 ? $id : (int) Configuration::get('PS_OS_PAYMENT');
    }

    public function awaitingStateId(): int
    {
        return (int) $this->get(self::OS_AWAITING);
    }

    public function preauthorizedStateId(): int
    {
        return (int) $this->get(self::OS_PREAUTHORIZED);
    }

    /** Payment can be offered: the connection to CMI is configured. */
    public function isPaymentConfigured(): bool
    {
        return $this->gatewayUrl() !== '' && $this->storeKey() !== ''
            && ($this->get(self::CLIENT_ID) !== '' || $this->get(self::CLIENT_ID_OFFSHORE) !== '');
    }

    public function hasApiCredentials(): bool
    {
        return $this->get(self::API_URL) !== '' && $this->get(self::API_USERNAME) !== '' && $this->get(self::API_PASSWORD) !== '';
    }

    /**
     * Whether the CMI API may be used: Complete Kit chosen and its credentials entered.
     * Everything that calls the API (actions, Check CMI, automatic checks) follows this, so a
     * Standard Kit shop never calls it, and a Complete Kit without credentials works like the
     * Standard Kit instead of offering actions that would fail.
     */
    public function isApiEnabled(): bool
    {
        return $this->get(self::KIT) === self::KIT_COMPLETE && $this->hasApiCredentials();
    }

    public function apiUrl(): string
    {
        return $this->get(self::API_URL);
    }

    public function apiUsername(): string
    {
        return $this->get(self::API_USERNAME);
    }

    public function apiPassword(): string
    {
        return $this->get(self::API_PASSWORD);
    }

    public function cronToken(): string
    {
        return $this->get(self::CRON_TOKEN);
    }
}
