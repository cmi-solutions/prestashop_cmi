<?php

/**
 * Test bench: install the module and configure it against the mock CMI.
 * Run inside a shop container: php /cmi-tests/configure.php [key=value ...]
 *   mode=automatic|manual  kit=complete|standard  currencies=504,978
 */
require __DIR__ . '/bootstrap.php';

$options = ['mode' => 'automatic', 'kit' => 'complete', 'currencies' => '504', 'install' => '1'];
foreach (array_slice($argv, 1) as $argument) {
    [$key, $value] = array_pad(explode('=', $argument, 2), 2, '');
    $options[$key] = $value;
}

// Never send e-mails from the bench.
Configuration::updateGlobalValue('PS_MAIL_METHOD', 3);

// MAD and EUR, with a real rate (1 EUR = 10.8 MAD), whichever is the shop's default currency.
$default = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$rates = $default->iso_code === 'MAD' ? ['MAD' => 1.0, 'EUR' => round(1 / 10.8, 6)] : ['EUR' => 1.0, 'MAD' => 10.8];
$names = ['MAD' => ['Dirham marocain', 'MAD', '504'], 'EUR' => ['Euro', '€', '978']];
foreach (['MAD', 'EUR'] as $iso) {
    $currency = new Currency((int) Currency::getIdByIsoCode($iso));
    if (!Validate::isLoadedObject($currency)) {
        $currency->iso_code = $iso;
        $currency->numeric_iso_code = $names[$iso][2];
        $currency->precision = 2;
        foreach (Language::getLanguages(false) as $language) {
            $currency->name[(int) $language['id_lang']] = $names[$iso][0];
            $currency->symbol[(int) $language['id_lang']] = $names[$iso][1];
        }
    }
    if ($iso !== $default->iso_code) {
        // Relative to the default currency.
        $currency->conversion_rate = $default->iso_code === 'EUR' || $default->iso_code === 'MAD'
            ? $rates[$iso]
            : $rates[$iso] * (float) (new Currency((int) Currency::getIdByIsoCode('EUR')))->conversion_rate;
    }
    $currency->active = true;
    $currency->save();
}
Shop::setContext(Shop::CONTEXT_ALL);

$module = Module::getInstanceByName('cmi');
if ($options['install'] === '1' && !Module::isInstalled('cmi')) {
    if (!$module->install()) {
        fwrite(STDERR, 'install failed: ' . implode(', ', $module->getErrors()) . "\n");
        exit(1);
    }
    echo "module installed\n";
}

// The demo carriers only deliver to Europe: put Morocco in that zone so checkout works.
$morocco = new Country((int) Country::getByIso('MA'));
$europe = (int) Zone::getIdByName('Europe');
if ($europe && (int) $morocco->id_zone !== $europe) {
    $morocco->id_zone = $europe;
    $morocco->active = true;
    $morocco->update();
}

// Allow the module for every currency (EUR may have been added after the module).
foreach (Currency::getCurrencies(false, false) as $currency) {
    Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'module_currency` (id_module, id_shop, id_currency) VALUES ('
        . (int) $module->id . ', ' . (int) Context::getContext()->shop->id . ', ' . (int) $currency['id_currency'] . ')');
}

foreach ([
    'CMI_CLIENT_ID' => '600000001',
    'CMI_CLIENT_ID_OFFSHORE' => '600000002',
    'CMI_GATEWAY_URL' => 'http://localhost:8090/fim/est3Dgate',
    'CMI_STORE_KEY' => 'TEST_store_key_2026',
    'CMI_API_URL' => 'https://cmi.test/fim/api',
    'CMI_API_USERNAME' => 'apiuser',
    'CMI_API_PASSWORD' => 'apipass',
    'CMI_KIT' => $options['kit'],
    'CMI_CONFIRMATION_MODE' => $options['mode'],
    'CMI_STORE_CURRENCIES' => $options['currencies'],
    // The scenarios expect captured orders in "Payment accepted", whatever was chosen by hand.
    'CMI_CAPTURED_STATE' => (string) (int) Configuration::get('PS_OS_PAYMENT'),
] as $key => $value) {
    Configuration::updateValue($key, $value);
}
echo 'configured: ' . json_encode($options) . "\n";
