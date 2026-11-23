<?php
/**
 * php-cs-fixer with the PrestaShop coding standard (prestashop/php-dev-tools):
 *   vendor/bin/php-cs-fixer fix            fix
 *   vendor/bin/php-cs-fixer fix --dry-run  check (CI)
 */
$config = new PrestaShop\CodingStandards\CsFixer\Config();
$config->setUsingCache(false)
    ->getFinder()
    ->in(__DIR__)
    ->exclude(['vendor', 'tests/e2e/mock/caddy-data']);

return $config;
