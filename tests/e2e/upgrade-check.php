<?php

/**
 * Test bench: upgrade from version 1.1.0.
 *   php upgrade-check.php before   with 1.1.0 installed: write 1.x settings and a 1.x-style paid order
 *   php upgrade-check.php after    with 2.0.0 upgraded: check what the upgrade did
 */
require __DIR__ . '/bootstrap.php';

use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Protocol\CmiProtocol;

$step = $argv[1] ?? '';
$failed = 0;
function check(string $label, bool $condition, string $detail = ''): void
{
    global $failed;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . ($condition || $detail === '' ? '' : " -- $detail") . "\n";
    $failed += $condition ? 0 : 1;
}

if ($step === 'before') {
    foreach ([
        'merchid' => '600000001', 'merchid_offshore' => '', 'actionslk' => 'http://localhost:8090/fim/est3Dgate',
        'secretkey' => 'TEST_store_key_2026', 'currency_store' => '504', 'confirmation_mode' => '2',
        'redirection_mode' => '1', 'cardholder_authentication' => '1', 'callback_mode' => '0',
        'actionslkapi' => 'https://cmi.test/fim/api', 'usernameapi' => 'apiuser', 'passwordapi' => 'apipass',
    ] as $key => $value) {
        Configuration::updateValue($key, $value);
    }
    // A 1.x order: created on the approved callback, OrderId = cart ID, TransId on the payment.
    $context = Context::getContext();
    $customer = new Customer();
    $customer->firstname = 'Legacy';
    $customer->lastname = 'Buyer';
    $customer->email = 'legacy+' . uniqid() . '@example.test';
    $customer->passwd = Tools::hash('Bench-password-2026');
    $customer->add();
    $address = new Address();
    $address->id_customer = (int) $customer->id;
    $address->id_country = (int) Country::getByIso('MA');
    $address->alias = 'Home';
    $address->firstname = 'Legacy';
    $address->lastname = 'Buyer';
    $address->address1 = '1 avenue Hassan II';
    $address->city = 'Rabat';
    $address->postcode = '10000';
    $address->add();
    $mad = new Currency((int) Currency::getIdByIsoCode('MAD'));
    $context->currency = $mad;
    $cart = new Cart();
    $cart->id_customer = (int) $customer->id;
    $cart->id_address_delivery = $cart->id_address_invoice = (int) $address->id;
    $cart->id_currency = (int) $mad->id;
    $cart->id_lang = (int) $context->language->id;
    $cart->secure_key = $customer->secure_key;
    $cart->add();
    $context->cart = $cart;
    $context->customer = $customer;
    $cart->updateQty(1, (int) Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1 AND price > 10 ORDER BY id_product'));
    $module = Module::getInstanceByName('cmi');
    $module->validateOrder((int) $cart->id, (int) Configuration::get('PS_OS_PAYMENT'), (float) $cart->getOrderTotal(true, Cart::BOTH), 'cmi', 'Paiement avec CMI', [], (int) $mad->id, false, $customer->secure_key);
    $order = Order::getByCartId((int) $cart->id);
    $payments = $order->getOrderPaymentCollection();
    $payments[0]->transaction_id = 'LEGACYTRANS1';
    $payments[0]->update();
    // A second cart, paid on CMI with 1.x just before the upgrade: its callback comes after.
    $inFlight = $cart->duplicate()['cart'];
    file_put_contents('/tmp/cmi-legacy.json', json_encode(['order' => (int) $order->id, 'cart' => (int) $cart->id, 'in_flight_cart' => (int) $inFlight->id, 'amount' => (float) $inFlight->getOrderTotal(true, Cart::BOTH)]));
    echo 'legacy order ' . $order->id . ' (cart ' . $cart->id . '), in-flight cart ' . $inFlight->id . ', module ' . $module->version . "\n";
    exit(0);
}

$legacy = json_decode((string) file_get_contents('/tmp/cmi-legacy.json'), true);
$module = Module::getInstanceByName('cmi');
check('module upgraded to 2.0.0', $module->version === '2.0.0' && Db::getInstance()->getValue('SELECT version FROM ' . _DB_PREFIX_ . "module WHERE name = 'cmi'") === '2.0.0');
check('client ID moved', Configuration::get('CMI_CLIENT_ID') === '600000001');
check('store key moved', Configuration::get('CMI_STORE_KEY') === 'TEST_store_key_2026');
check('payment page URL moved', Configuration::get('CMI_GATEWAY_URL') === 'http://localhost:8090/fim/est3Dgate');
check('manual confirmation kept', Configuration::get('CMI_CONFIRMATION_MODE') === 'manual');
check('1.x callback setting dropped (CMI always calls the shop now)', !Configuration::hasKey('CMI_CALLBACK_MODE') && !Configuration::hasKey('callback_mode'));
check('automatic redirect kept', Configuration::get('CMI_AUTO_REDIRECT') === '1');
check('1.x 3-D Secure setting dropped (always 3-D Secure now)', !Configuration::hasKey('CMI_3D_SECURE') && !Configuration::hasKey('cardholder_authentication'));
check('API credentials moved, Complete Kit chosen', Configuration::get('CMI_API_USERNAME') === 'apiuser' && Configuration::get('CMI_KIT') === 'complete');
check('store currencies moved', Configuration::get('CMI_STORE_CURRENCIES') === '504');
check('order ID prefix generated', (bool) preg_match('/^PS[0-9A-F]{4}-$/', (string) Configuration::get('CMI_OID_PREFIX')));
check('old settings removed', !Configuration::hasKey('merchid') && !Configuration::hasKey('secretkey') && !Configuration::hasKey('BPI_ID_ORDERSTATE'));
check('old state "Waiting for CMI validation" reused as "Awaiting CMI payment"', (int) Configuration::get('CMI_OS_AWAITING') > 0 && (new OrderState((int) Configuration::get('CMI_OS_AWAITING')))->logable == false);
check('pre-authorized state created', (int) Configuration::get('CMI_OS_PREAUTHORIZED') > 0);
check('tables created', (bool) Db::getInstance()->executeS("SHOW TABLES LIKE '" . _DB_PREFIX_ . "cmi_transaction'"));
check('unused 1.x table dropped', !Db::getInstance()->executeS("SHOW TABLES LIKE '" . _DB_PREFIX_ . "bpi_secuaccess'"));
foreach (['displayAdminOrderMain', 'displayPaymentReturn', 'actionOrderGridDefinitionModifier', 'paymentOptions', 'displayRightColumn'] as $hook) {
    check("hook $hook registered", (bool) $module->isRegisteredInHook($hook));
}
check('admin controller installed', (bool) Tab::getIdFromClassName('AdminCmiPayment'));
foreach (['confirmOf.php', 'fr.php', 'views/css/font-awesome.min.css', 'views/templates/admin/admin.tpl'] as $file) {
    check("1.x file $file deleted", !is_file(_PS_MODULE_DIR_ . 'cmi/' . $file));
}

// The 1.x order gets a transaction record (OrderId = cart ID) and Check CMI works on it.
$order = new Order((int) $legacy['order']);
$tx = $module->services()->legacy()->forOrder($order, 'cmi');
check('1.x order: transaction with OrderId = cart ID', $tx && $tx->oid === (string) $legacy['cart'] && $tx->legacy && $tx->payment_state === '');
check('1.x order: MAD amount known, TransId kept for the ownership check', $tx && $tx->hasKnownAmount() && in_array('LEGACYTRANS1', $tx->getOwnIds(), true));

// A payment started with 1.x, answered by CMI after the upgrade (OrderId = cart ID, old confirm URL).
$fields = [
    'clientid' => '600000001', 'oid' => (string) $legacy['in_flight_cart'], 'amount' => CmiProtocol::formatAmount($legacy['amount']),
    'currency' => '504', 'Response' => 'Approved', 'ProcReturnCode' => '00', 'TransId' => 'INFLIGHT1', 'HostRefNum' => '123',
    'AuthCode' => '111111', 'CallbackResponse' => 'true', 'rnd' => 'x',
];
$fields['HASH'] = CmiProtocol::callbackHash($fields, 'TEST_store_key_2026');
$url = Context::getContext()->link->getModuleLink('cmi', 'confirm');
$parts = parse_url($url);
$curl = curl_init('http://127.0.0.1' . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : ''));
curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Host: ' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')]]);
$answer = trim((string) curl_exec($curl));
curl_close($curl);
Cache::clean('*');
$inFlightOrder = Order::getByCartId((int) $legacy['in_flight_cart']);
$inFlightTx = $module->services()->repository()->findByCart((int) $legacy['in_flight_cart']);
check('1.x payment answered after the upgrade: callback accepted on the old URL', $answer === 'APPROVED', $answer);
check('...its order is created, pre-authorized (manual mode)', $inFlightOrder && $inFlightTx && $inFlightTx->payment_state === PaymentState::PRE_CONFIRMATION_REQUIRED
    && (int) $inFlightOrder->getCurrentState() === (int) Configuration::get('CMI_OS_PREAUTHORIZED'), $inFlightTx ? $inFlightTx->payment_state : 'no transaction');

echo $failed ? "$failed upgrade check(s) failed\n" : "All upgrade checks passed.\n";
exit($failed ? 1 : 0);
