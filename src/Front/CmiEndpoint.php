<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * For front controllers that CMI's servers (or a cron job) call: they must answer even when
 * the shop is in maintenance or restricts countries, and answer only what the module writes.
 */
trait CmiEndpoint
{
    /** CMI must reach the shop during maintenance too. */
    protected function displayMaintenancePage()
    {
    }

    /** CMI's servers are not customers: never apply the country restriction. */
    protected function displayRestrictedCountryPage()
    {
    }

    /** @return array<string, string> the POST fields sent (scalars only) */
    protected function postFields(): array
    {
        $fields = [];
        foreach ($_POST as $name => $value) {
            if (is_scalar($value)) {
                $fields[(string) $name] = (string) $value;
            }
        }

        return $fields;
    }

    /** Send a plain-text answer and stop, with nothing else in the response. */
    protected function answer(string $text, int $status = 200): void
    {
        while (ob_get_level() > 0 && @ob_end_clean()) {
            continue;
        }
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $text;
        exit;
    }
}
