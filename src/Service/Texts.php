<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Service;

use Language;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Translated texts of the services. Notes written to an order's CMI history use the shop's
 * default language, whoever triggered them (a CMI callback carries no language).
 */
class Texts
{
    public const DOMAIN_ADMIN = 'Modules.Cmi.Admin';
    public const DOMAIN_SHOP = 'Modules.Cmi.Shop';

    /** @var \Module */
    private $module;
    /** @var string|null */
    private $defaultLocale;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    /** Text for the merchant, in the shop's default language. */
    public function admin(string $text, array $parameters = []): string
    {
        return $this->module->getTranslator()->trans($text, $parameters, self::DOMAIN_ADMIN, $this->defaultLocale());
    }

    private function defaultLocale(): ?string
    {
        if ($this->defaultLocale === null) {
            $language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
            $this->defaultLocale = (string) $language->locale;
        }

        return $this->defaultLocale !== '' ? $this->defaultLocale : null;
    }
}
