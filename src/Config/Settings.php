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
    /** Offshore store (foreign currencies): not offered for now. Kept so a 1.x value is not lost. */
    public const CLIENT_ID_OFFSHORE = 'CMI_CLIENT_ID_OFFSHORE';
    public const GATEWAY_URL = 'CMI_GATEWAY_URL';
    public const STORE_KEY = 'CMI_STORE_KEY';
    /** Currencies of the CMI store (1.x setting, kept): payments are MAD-only for now. */
    public const STORE_CURRENCIES = 'CMI_STORE_CURRENCIES';
    public const KIT = 'CMI_KIT';
    public const API_URL = 'CMI_API_URL';
    public const API_USERNAME = 'CMI_API_USERNAME';
    public const API_PASSWORD = 'CMI_API_PASSWORD';
    public const CONFIRMATION_MODE = 'CMI_CONFIRMATION_MODE';
    /** Removed setting: CMI always calls the shop (server callback). Only deleted on uninstall. */
    public const CALLBACK_MODE = 'CMI_CALLBACK_MODE';
    /** Removed setting: payments always go through 3-D Secure. Only deleted on uninstall. */
    public const THREE_D_SECURE = 'CMI_3D_SECURE';
    public const AUTO_REDIRECT = 'CMI_AUTO_REDIRECT';
    public const PAYMENT_LANGUAGE = 'CMI_PAYMENT_LANGUAGE';
    public const OID_PREFIX = 'CMI_OID_PREFIX';
    public const CAPTURED_STATE = 'CMI_CAPTURED_STATE';
    public const TITLE = 'CMI_TITLE';
    public const DESCRIPTION = 'CMI_DESCRIPTION';
    public const CRON_TOKEN = 'CMI_CRON_TOKEN';
    /** Uninstall removes the payment history and order states too (off by default). */
    public const DELETE_DATA_ON_UNINSTALL = 'CMI_DELETE_DATA_ON_UNINSTALL';
    /** CMI platform: test or production. */
    public const ENVIRONMENT = 'CMI_ENVIRONMENT';

    /** Order states installed by the module. */
    public const OS_AWAITING = 'CMI_OS_AWAITING';
    public const OS_PREAUTHORIZED = 'CMI_OS_PREAUTHORIZED';
    /** Order state the merchant created with "Custom" in "Order status after capture". */
    public const OS_CUSTOM_CAPTURED = 'CMI_OS_CUSTOM_CAPTURED';

    public const KIT_STANDARD = 'standard';
    public const KIT_COMPLETE = 'complete';
    public const MODE_AUTOMATIC = 'automatic';
    public const MODE_MANUAL = 'manual';
    public const ENV_TEST = 'test';
    public const ENV_PRODUCTION = 'production';

    /** CMI's addresses for each platform: the payment page and the XML API. */
    public const PLATFORM_URLS = [
        self::ENV_TEST => ['gateway' => 'https://testpayment.cmi.co.ma/fim/est3Dgate', 'api' => 'https://testpayment.cmi.co.ma/fim/api'],
        self::ENV_PRODUCTION => ['gateway' => 'https://payment.cmi.co.ma/fim/est3Dgate', 'api' => 'https://payment.cmi.co.ma/fim/api'],
    ];

    /** Every key the module writes, removed on uninstall. */
    public const ALL_KEYS = [
        self::CLIENT_ID, self::CLIENT_ID_OFFSHORE, self::GATEWAY_URL, self::STORE_KEY, self::STORE_CURRENCIES,
        self::KIT, self::API_URL, self::API_USERNAME, self::API_PASSWORD, self::CONFIRMATION_MODE,
        self::CALLBACK_MODE, self::THREE_D_SECURE, self::AUTO_REDIRECT, self::PAYMENT_LANGUAGE, self::OID_PREFIX,
        self::CAPTURED_STATE, self::TITLE, self::DESCRIPTION, self::CRON_TOKEN, self::OS_AWAITING, self::OS_PREAUTHORIZED,
        self::OS_CUSTOM_CAPTURED, self::ENVIRONMENT, self::DELETE_DATA_ON_UNINSTALL,
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
            ? \Configuration::get($key)
            : \Configuration::get($key, null, null, $this->idShop);

        return self::text($value);
    }

    public function getLang(string $key, int $idLang): string
    {
        $value = \Configuration::get($key, $idLang, null, $this->idShop);

        return self::text($value);
    }

    /** A saved value as text: '' when there is none (false or null). */
    private static function text($value): string
    {
        return is_scalar($value) && $value !== false ? trim((string) $value) : '';
    }

    public function clientId(string $account): string
    {
        return $this->get($account === AccountResolver::OFFSHORE ? self::CLIENT_ID_OFFSHORE : self::CLIENT_ID);
    }

    public function gatewayUrl(): string
    {
        return $this->get(self::GATEWAY_URL);
    }

    /**
     * The CMI platform chosen: test or production. Shops upgraded from 1.x have no such
     * setting yet: it follows the payment page URL they entered.
     */
    public function environment(): string
    {
        $environment = $this->get(self::ENVIRONMENT);
        if (in_array($environment, [self::ENV_TEST, self::ENV_PRODUCTION], true)) {
            return $environment;
        }

        return self::environmentOfUrl($this->gatewayUrl()) ?? self::ENV_PRODUCTION;
    }

    public function isTestMode(): bool
    {
        return $this->environment() === self::ENV_TEST;
    }

    /** The CMI platform an address belongs to, or null for another address (mock, proxy...). */
    public static function environmentOfUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::PLATFORM_URLS as $environment => $urls) {
            if ($host !== '' && $host === strtolower((string) parse_url($urls['gateway'], PHP_URL_HOST))) {
                return $environment;
            }
        }

        return null;
    }

    public function storeKey(): string
    {
        return $this->get(self::STORE_KEY);
    }

    /**
     * Currencies the CMI store accepts (ISO 4217 numeric codes). MAD only for now, as in the
     * WooCommerce plugin: orders in other currencies are converted to MAD. The offshore store
     * (payments in foreign currencies) may come back later.
     *
     * @return string[]
     */
    public function storeCurrencies(): array
    {
        return [AccountResolver::MAD_NUMERIC];
    }

    public function confirmationMode(): string
    {
        return $this->get(self::CONFIRMATION_MODE) === self::MODE_MANUAL ? self::MODE_MANUAL : self::MODE_AUTOMATIC;
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

        return $id > 0 ? $id : (int) \Configuration::get('PS_OS_PAYMENT');
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
        return $this->gatewayUrl() !== '' && $this->storeKey() !== '' && $this->get(self::CLIENT_ID) !== '';
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
