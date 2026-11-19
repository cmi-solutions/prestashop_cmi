<?php
/**
 * Test bench scenarios: real orders, real HTTP calls to the module's controllers, the mock CMI
 * for the payment page, callbacks and API. Run inside a shop container:
 *   php /cmi-tests/harness.php [scenario ...]
 * Prints one line per check and exits 1 when one fails.
 */
require __DIR__ . '/bootstrap.php';

use Cmi\PrestaShop\Model\CmiTransaction;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Protocol\CmiProtocol;

const MOCK = 'http://cmi-app:8000';
const STORE_KEY = 'TEST_store_key_2026';

$results = ['passed' => 0, 'failed' => []];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if ($condition) {
        ++$results['passed'];
        echo "  ok   $label\n";
    } else {
        $results['failed'][] = $label;
        echo "  FAIL $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

/** HTTP to the shop itself (Apache on port 80 in this container, under the shop's public host). */
function shop(string $method, string $url, array $fields = []): array
{
    $parts = parse_url($url);
    $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $target = 'http://127.0.0.1' . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $curl = curl_init($target);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Host: ' . $host],
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $raw = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $headerSize), $location);

    return ['status' => $status, 'body' => trim(substr($raw, $headerSize)), 'location' => trim($location[1] ?? '')];
}

function mock(string $path, $body = null, bool $json = true): array
{
    $curl = curl_init(MOCK . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json ? json_encode($body) : http_build_query($body));
    }
    $response = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $decoded = json_decode($response, true);

    return ['status' => $status, 'body' => $response, 'json' => is_array($decoded) ? $decoded : null];
}

function services(): Cmi\PrestaShop\Services
{
    return Module::getInstanceByName('cmi')->services();
}

function setting(string $key, string $value): void
{
    Configuration::updateValue($key, $value);
}

/** Objects changed by the shop's HTTP requests: never read them from this process's cache. */
function fresh(): void
{
    Cache::clean('*');
    if (method_exists('ObjectModel', 'resetStaticCache')) {
        ObjectModel::resetStaticCache();
    }
}

function reload(CmiTransaction $transaction): CmiTransaction
{
    fresh();

    return new CmiTransaction((int) $transaction->id);
}

function orderState(CmiTransaction $transaction): int
{
    fresh();
    $order = new Order((int) $transaction->id_order);

    return (int) $order->getCurrentState();
}

/**
 * A customer orders one product and is sent to the (mock) CMI payment page.
 *
 * @return array{tx: CmiTransaction, order: Order, page: array, fields: array}
 */
function newOrder(string $currencyIso = 'MAD', string $firstname = 'Said', string $lastname = 'El Idrissi'): array
{
    $context = Context::getContext();
    $context->link = new Link();
    $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
    $context->currency = new Currency((int) Currency::getIdByIsoCode($currencyIso));

    $customer = new Customer();
    $customer->firstname = 'Said';
    $customer->lastname = 'Bench';
    $customer->email = 'buyer+' . uniqid() . '@example.test';
    $customer->passwd = Tools::hash('Bench-password-2026');
    $customer->is_guest = false;
    $customer->add();

    $address = new Address();
    $address->id_customer = (int) $customer->id;
    $address->id_country = (int) Country::getByIso('MA');
    $address->alias = 'Home';
    $address->firstname = $firstname;
    $address->lastname = $lastname;
    $address->address1 = '15 rue Ibn Battouta';
    $address->city = 'Fès';
    $address->postcode = '30000';
    $address->phone = '+212 600-000-000';
    $address->add();

    $cart = new Cart();
    $cart->id_customer = (int) $customer->id;
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->id_currency = (int) $context->currency->id;
    $cart->id_lang = (int) $context->language->id;
    $cart->id_shop = (int) $context->shop->id;
    $cart->secure_key = $customer->secure_key;
    $cart->add();
    $context->cart = $cart;
    $context->customer = $customer;
    $product =(int) Db::getInstance()->getValue('SELECT p.id_product FROM ' . _DB_PREFIX_ . 'product p INNER JOIN ' . _DB_PREFIX_ . 'stock_available s ON s.id_product = p.id_product AND s.id_product_attribute = 0 WHERE p.active = 1 AND s.quantity > 5 AND p.price > 10 ORDER BY p.id_product');
    $cart->updateQty(1, $product);
    $context->cart = $cart;
    $context->customer = $customer;

    $module = Module::getInstanceByName('cmi');
    $request = services()->paymentRequest();
    $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
    $charge = $request->charge($context->currency, $total);
    if (!is_array($charge)) {
        throw new RuntimeException('charge refused: ' . $charge);
    }
    $module->validateOrder((int) $cart->id, services()->settings()->awaitingStateId(), $total, $module->displayName, null, [], (int) $context->currency->id, false, $customer->secure_key);
    $order = Order::getByCartId((int) $cart->id);
    $tx = $request->recordAttempt([$order], $charge);
    $fields = $request->formFields($tx, $customer, $address, 'fr', $context->link);
    // The browser posts the signed form to CMI's payment page.
    $page = mock('/fim/est3Dgate', $fields, false);

    return ['tx' => $tx, 'order' => $order, 'page' => $page, 'fields' => $fields];
}

function pay(CmiTransaction $tx, string $outcome, bool $loseCallback = false): array
{
    $answer = mock('/mock/pay', ['oid' => $tx->oid, 'outcome' => $outcome] + ($loseCallback ? ['no_callback' => '1'] : []), false);
    $state = mock('/mock/state?oid=' . urlencode($tx->oid))['json'];

    return ['page' => $answer, 'state' => $state, 'callback' => end($state['callbacks'])];
}

/** The customer's browser comes back to the shop with CMI's signed result. */
function browserReturn(array $state): array
{
    $result = $state['last_result'];
    $url = $result['ProcReturnCode'] === '00' ? $result['okUrl'] : $result['failUrl'];

    return shop('POST', $url, $result);
}

function signed(array $fields): array
{
    return mock('/mock/sign', $fields)['json'];
}

$states = [
    'paid' => (int) Configuration::get('PS_OS_PAYMENT'),
    'error' => (int) Configuration::get('PS_OS_ERROR'),
    'canceled' => (int) Configuration::get('PS_OS_CANCELED'),
    'refund' => (int) Configuration::get('PS_OS_REFUND'),
    'awaiting' => (int) Configuration::get('CMI_OS_AWAITING'),
    'preauth' => (int) Configuration::get('CMI_OS_PREAUTHORIZED'),
];

$scenarios = [];

$scenarios['automatic'] = static function () use ($states) {
    setting('CMI_CONFIRMATION_MODE', 'automatic');
    setting('CMI_CALLBACK_MODE', 'server');
    $o = newOrder();
    $tx = $o['tx'];
    check('order created awaiting CMI payment', orderState($tx) === $states['awaiting']);
    check('OrderId = prefix + order reference', $tx->oid === Configuration::get('CMI_OID_PREFIX') . $o['order']->reference, $tx->oid);
    check('mock CMI accepts the signed payment form', $o['page']['status'] === 200 && strpos($o['page']['body'], 'Mock CMI payment page') !== false, substr($o['page']['body'], 0, 200));
    check('form sends MAD on the standard store', $o['fields']['currency'] === '504' && $o['fields']['clientid'] === '600000001');
    check('form keeps the + of the phone', $o['fields']['tel'] === '+212 600000000', $o['fields']['tel']);
    check('form has no accents in the city', $o['fields']['BillToCity'] === 'Fes');
    check('form uses 3-D Secure and the server callback', $o['fields']['storetype'] === '3DPAYHOSTING' && $o['fields']['CallbackResponse'] === 'true' && !empty($o['fields']['callbackUrl']));

    $paid = pay($tx, 'approve');
    check('callback answered ACTION=POSTAUTH', $paid['callback']['body'] === 'ACTION=POSTAUTH', json_encode($paid['callback']));
    $tx = reload($tx);
    check('payment captured', $tx->payment_state === PaymentState::CONFIRMED);
    check('order paid', orderState($tx) === $states['paid'], (string) orderState($tx));
    $payments = OrderPayment::getByOrderReference($o['order']->reference);
    check('order payment carries the CMI TransId and card', $payments && $payments[0]->transaction_id === $paid['state']['last_result']['TransId'] && $payments[0]->card_number === '**** 1111', json_encode(array_map(static function ($p) { return [$p->transaction_id, $p->card_number]; }, $payments)));

    $back = browserReturn($paid['state']);
    $result = shop('GET', $back['location']);
    check('browser return redirects to the order confirmation', $back['status'] === 302 && strpos($result['location'], 'id_module=') !== false && strpos($result['location'], 'id_order=') !== false,$back['status'] . ' ' . $back['location'] . ' -> ' . $result['location']);

    $replay = shop('POST', $paid['state']['last_result']['callbackUrl'], $paid['state']['last_result']);
    check('a repeated callback changes nothing', $replay['body'] === 'APPROVED' && orderState(reload($tx)) === $states['paid'], $replay['body']);

    $tampered = $paid['state']['last_result'];
    $tampered['amount'] = '1.00';
    $bad = shop('POST', $tampered['callbackUrl'], $tampered);
    check('a tampered callback is refused with 400', $bad['status'] === 400, $bad['status'] . ' ' . $bad['body']);
    $unsigned = $paid['state']['last_result'];
    unset($unsigned['HASH']);
    check('a callback without signature is refused', shop('POST', $unsigned['callbackUrl'], $unsigned)['status'] === 400);
    check('the callback refuses GET', shop('GET', $unsigned['callbackUrl'])['status'] === 405);

    // Refunds: partial, then the rest.
    $manager = services()->manager();
    $total = (float) $tx->amount_charged;
    $first = $manager->refund($tx, 10.0, 'damaged box');
    $tx = reload($tx);
    check('partial refund accepted by CMI', $first->success && abs((float) $tx->refunded - 10.0) < 0.001, $first->message);
    check('order still paid after a partial refund', orderState($tx) === $states['paid'] && $tx->payment_state === PaymentState::CONFIRMED);
    $tooMuch = $manager->refund($tx, $total, '');
    check('refund above the balance refused before calling CMI', !$tooMuch->success && $tooMuch->code === 'amount_exceeded', $tooMuch->message);
    $rest = $manager->refund($tx, null, '');
    $tx = reload($tx);
    check('refund of the remaining balance', $rest->success && $tx->payment_state === PaymentState::REFUNDED && abs((float) $tx->refunded - $total) < 0.001, $rest->message);
    check('order refunded', orderState($tx) === $states['refund']);
    $credits = array_filter(mock('/mock/state?oid=' . urlencode($tx->oid))['json']['ops'], static function ($op) { return $op['type'] === 'C'; });
    check('CMI holds the two credits', count($credits) === 2 && array_sum(array_column($credits, 'amount')) === (int) round($total * 100));
};

$scenarios['manual'] = static function () use ($states) {
    setting('CMI_CONFIRMATION_MODE', 'manual');
    $o = newOrder();
    $paid = pay($o['tx'], 'approve');
    $tx = reload($o['tx']);
    check('manual mode: callback answered APPROVED', $paid['callback']['body'] === 'APPROVED', json_encode($paid['callback']));
    check('manual mode: payment pre-authorized', $tx->payment_state === PaymentState::PRE_CONFIRMATION_REQUIRED);
    check('manual mode: order in "authorized, to confirm"', orderState($tx) === $states['preauth']);
    $refund = services()->manager()->refund($tx, null, '');
    check('a pre-authorization cannot be refunded', !$refund->success && $refund->code === 'not_captured');
    check('fresh pre-authorization: no age warning', services()->manager()->preauthWarningDays($tx) === 0);
    $confirm = services()->manager()->confirm($tx);
    $tx = reload($tx);
    check('Confirm captures the payment', $confirm->success && $tx->payment_state === PaymentState::CONFIRMED, $confirm->message);
    check('Confirm marks the order paid', orderState($tx) === $states['paid']);
    $again = services()->manager()->confirm($tx);
    check('a second Confirm is harmless', $again->success && $again->code === 'already_confirmed');

    $o2 = newOrder();
    pay($o2['tx'], 'approve');
    $void = services()->manager()->void(reload($o2['tx']));
    $tx2 = reload($o2['tx']);
    check('Void cancels the pre-authorization', $void->success && $tx2->payment_state === PaymentState::VOIDED, $void->message);
    check('Void cancels the order', orderState($tx2) === $states['canceled']);
    $confirmVoided = services()->manager()->confirm($tx2);
    check('a voided payment cannot be confirmed', !$confirmVoided->success);
    setting('CMI_CONFIRMATION_MODE', 'automatic');
};

$scenarios['decline'] = static function () use ($states) {
    $o = newOrder();
    $declined = pay($o['tx'], 'decline');
    $tx = reload($o['tx']);
    check('declined payment: order in payment error', $tx->payment_state === PaymentState::FAILED && orderState($tx) === $states['error'], json_encode($declined['callback']));
    $back = browserReturn($declined['state']);
    $page = shop('GET', $back['location']);
    check('declined: the customer sees the result page with "Pay again"', $page['status'] === 200 && strpos($page['body'], 'controller=validation') !== false || strpos($page['body'], '/validation?') !== false || strpos($page['body'], 'id_order=') !== false, $back['location']);

    // The customer pays again: same OrderId, approved this time.
    $request = services()->paymentRequest();
    $tx = $request->recordAttempt([$o['order']], $request->charge(new Currency((int) $o['order']->id_currency), (float) $o['order']->total_paid_tax_incl), $tx);
    services()->orders()->setStateIfIn($tx, $states['awaiting'], [$states['error']]);
    $fields = $request->formFields($tx, new Customer((int) $o['order']->id_customer), new Address((int) $o['order']->id_address_invoice), 'fr', Context::getContext()->link);
    mock('/fim/est3Dgate', $fields, false);
    $paid = pay($tx, 'approve');
    $tx = reload($tx);
    check('approved after a decline: order paid', $tx->payment_state === PaymentState::CONFIRMED && orderState($tx) === $states['paid'], json_encode($paid['callback']));
    // The earlier decline arrives again (late failUrl post): ignored.
    $late = shop('POST', $declined['state']['last_result']['callbackUrl'], $declined['state']['last_result']);
    check('a late decline never downgrades a paid order', $late['body'] === 'APPROVED' && orderState(reload($tx)) === $states['paid'], $late['body']);
};

$scenarios['browser-first'] = static function () use ($states) {
    $o = newOrder();
    $paid = pay($o['tx'], 'approve', true);
    $back = browserReturn($paid['state']);
    $tx = reload($o['tx']);
    check('browser return before the callback: authorized, not yet captured', $tx->payment_state === PaymentState::PRE_CONFIRMATION_REQUIRED && orderState($tx) === $states['preauth'], $back['status'] . ' ' . $tx->payment_state);
    $callback = shop('POST', $paid['state']['last_result']['callbackUrl'], $paid['state']['last_result']);
    $tx = reload($tx);
    check('the late callback then captures it', $callback['body'] === 'ACTION=POSTAUTH' && $tx->payment_state === PaymentState::CONFIRMED && orderState($tx) === $states['paid'], $callback['body']);
};

$scenarios['browser-mode'] = static function () use ($states) {
    // "Browser return only": CMI does not call the shop; automatic mode captures via the API.
    setting('CMI_CALLBACK_MODE', 'browser');
    $o = newOrder();
    check('browser mode: no callbackUrl, CallbackResponse=false', !isset($o['fields']['callbackUrl']) && $o['fields']['CallbackResponse'] === 'false');
    $paid = pay($o['tx'], 'approve');
    check('browser mode: CMI did not call the shop', $paid['callback'] === null);
    browserReturn($paid['state']);
    $tx = reload($o['tx']);
    check('browser mode: captured through the API on return', $tx->payment_state === PaymentState::CONFIRMED && orderState($tx) === $states['paid'], $tx->payment_state);
    $ops = mock('/mock/state?oid=' . urlencode($tx->oid))['json']['ops'];
    check('browser mode: CMI shows the capture', $ops[0]['stat'] === 'C');
    setting('CMI_CALLBACK_MODE', 'server');
};

$scenarios['cancel'] = static function () use ($states) {
    $o = newOrder();
    $cancel = shop('GET', $o['fields']['shopurl']);
    $tx = reload($o['tx']);
    check('customer cancel without payment cancels the order', $tx->payment_state === PaymentState::CLIENT_CANCELLED && orderState($tx) === $states['canceled'], $cancel['status'] . ' ' . $tx->payment_state);

    $o2 = newOrder();
    pay($o2['tx'], 'approve');
    shop('GET', $o2['fields']['shopurl']);
    $tx2 = reload($o2['tx']);
    check('customer cancel after paying changes nothing', $tx2->payment_state === PaymentState::CONFIRMED && orderState($tx2) === $states['paid']);
    $forged = shop('GET', preg_replace('/key=[^&]+/', 'key=wrong', $o2['fields']['shopurl']));
    check('cancel link with a wrong key is refused', orderState(reload($tx2)) === $states['paid'] && $forged['status'] === 302);
};

$scenarios['foreign'] = static function () use ($states) {
    setting('CMI_CONFIRMATION_MODE', 'manual');
    $o = newOrder();
    pay($o['tx'], 'approve', true);
    // Same OrderId, but CMI shows another website's older, captured payment of another amount.
    $tx = reload($o['tx']);
    $tx->own_ids = '[]';
    $tx->payment_state = PaymentState::PRE_CONFIRMATION_REQUIRED;
    $tx->save();
    $data = mock('/mock/state?oid=' . urlencode($tx->oid))['json'];
    $seedOid = 'FOREIGN-' . uniqid();
    mock('/mock/seed', ['oid' => $seedOid, 'amount' => 999, 'days_ago' => 40]);
    $tx->oid = $seedOid;
    $tx->save();
    $before = count(mock('/mock/state')['json']['api_log'] ?? []);
    $result = services()->manager()->confirm(reload($tx));
    $log = array_slice(mock('/mock/state')['json']['api_log'], $before);
    check('another website\'s payment is never confirmed', !$result->success && $result->code === 'foreign', $result->code . ' ' . $result->message);
    check('only read-only queries were sent', !array_filter($log, static function ($line) { return strpos($line, 'PostAuth') === 0; }), json_encode($log));
    $refund = services()->manager()->refund(reload($tx), null, '');
    check('nor refunded', !$refund->success);
    setting('CMI_CONFIRMATION_MODE', 'automatic');
};

$scenarios['pending'] = static function () use ($states) {
    $o = newOrder();
    pay($o['tx'], 'approve', true); // callback lost, customer never came back
    $tx = reload($o['tx']);
    check('lost callback: order still awaiting', orderState($tx) === $states['awaiting'] && (int) $tx->next_check_at > time());
    Db::getInstance()->update('cmi_transaction', ['next_check_at' => time() - 1], 'id_cmi_transaction = ' . (int) $tx->id);
    $cronUrl = Context::getContext()->link->getModuleLink('cmi', 'cron', ['token' => Configuration::get('CMI_CRON_TOKEN')]);
    $cron = shop('GET', $cronUrl);
    $tx = reload($tx);
    check('cron runs the due check', $cron['status'] === 200 && strpos($cron['body'], 'OK') === 0, $cron['status'] . ' ' . $cron['body']);
    check('automatic check finds the pre-authorization', $tx->payment_state === PaymentState::PRE_CONFIRMATION_REQUIRED && orderState($tx) === $states['preauth'], $tx->payment_state);
    check('no further check scheduled', (int) $tx->next_check_at === 0);
    check('cron refuses a wrong token', shop('GET', preg_replace('/token=[^&]+/', 'token=nope', $cronUrl))['status'] === 403);

    // Abandoned order: nothing at CMI at the last check -> cancelled.
    $o2 = newOrder();
    $tx2 = reload($o2['tx']);
    Db::getInstance()->update('cmi_transaction', ['next_check_at' => time() - 1, 'pending_checks' => 2], 'id_cmi_transaction = ' . (int) $tx2->id);
    shop('GET', $cronUrl);
    $tx2 = reload($tx2);
    check('abandoned order cancelled at the last check', orderState($tx2) === $states['canceled'] && $tx2->payment_state === PaymentState::FAILED, (string) orderState($tx2));
};

$scenarios['back-office'] = static function () use ($states) {
    $o = newOrder();
    pay($o['tx'], 'approve');
    $tx = reload($o['tx']);
    mock('/mock/back-office', ['oid' => $tx->oid, 'action' => 'refund', 'amount' => 1500]);
    $refund = services()->manager()->refund($tx, 5.0, '');
    check('refund refused while a back-office refund is unrecorded', !$refund->success && $refund->code === 'out_of_sync', $refund->message);
    $check = services()->manager()->check(reload($tx));
    $tx = reload($tx);
    check('Check CMI records the back-office refund', $check->code === 'PARTIALLY_REFUNDED' && abs((float) $tx->refunded - 15.0) < 0.001, $check->code . ' ' . $check->message);
    check('order still paid', orderState($tx) === $states['paid']);

    // Pre-authorization confirmed in the CMI back office: Confirm refused, order updated.
    setting('CMI_CONFIRMATION_MODE', 'manual');
    $o2 = newOrder();
    pay($o2['tx'], 'approve');
    mock('/mock/back-office', ['oid' => $o2['tx']->oid, 'action' => 'capture']);
    $confirm = services()->manager()->confirm(reload($o2['tx']));
    $tx2 = reload($o2['tx']);
    check('Confirm refused when already captured in the back office', !$confirm->success && $confirm->code === 'already_captured', $confirm->message);
    check('...and the order is updated to paid', $tx2->payment_state === PaymentState::CONFIRMED && orderState($tx2) === $states['paid']);
    setting('CMI_CONFIRMATION_MODE', 'automatic');
};

$scenarios['currency'] = static function () use ($states) {
    setting('CMI_STORE_CURRENCIES', '504');
    $o = newOrder('EUR');
    $tx = $o['tx'];
    $eur = new Currency((int) Currency::getIdByIsoCode('EUR'));
    $mad = new Currency((int) Currency::getIdByIsoCode('MAD'));
    $expectedRate = (float) $mad->conversion_rate / (float) $eur->conversion_rate;
    check('EUR order charged in MAD', $o['fields']['currency'] === '504' && $tx->currency_iso === 'MAD' && $tx->order_currency === 'EUR');
    check('rate frozen at payment', abs((float) $tx->rate - $expectedRate) < 0.0001, (string) $tx->rate);
    check('MAD amount = EUR total x rate', abs((float) $o['fields']['amount'] - round((float) $o['order']->total_paid_tax_incl * $expectedRate, 2)) < 0.011, $o['fields']['amount']);
    check('CMI page shows the EUR amount', isset($o['fields']['amountCur'], $o['fields']['symbolCur']) && $o['fields']['symbolCur'] === 'EUR');
    pay($tx, 'approve');
    $refund = services()->manager()->refund(reload($tx), 20.0, '');
    check('refund in MAD of a EUR order', $refund->success && strpos($refund->message, 'EUR') !== false, $refund->message);

    // Offshore store: EUR charged in EUR with the offshore client ID.
    setting('CMI_STORE_CURRENCIES', '504,978');
    $off = newOrder('EUR');
    check('offshore: EUR charged in EUR', $off['fields']['currency'] === '978' && $off['fields']['clientid'] === '600000002' && !isset($off['fields']['amountCur']));
    pay($off['tx'], 'approve');
    $offRefund = services()->manager()->refund(reload($off['tx']), 1.0, '');
    check('offshore refund sent in EUR', $offRefund->success, $offRefund->message);
    setting('CMI_STORE_CURRENCIES', '504');

    // A rate never set up (1 EUR = 1 MAD) is refused.
    $original = (float) $eur->conversion_rate;
    $eur->conversion_rate = (float) $mad->conversion_rate;
    check('a rate of exactly 1 is refused', services()->paymentRequest()->charge($eur, 100.0) === 'rate');
    $eur->conversion_rate = $original;
};

$scenarios['names'] = static function () {
    $address = new Address();
    $address->firstname = 'محمد';
    $address->lastname = 'العلوي';
    check('a name in Arabic only is detected', !services()->paymentRequest()->hasValidName($address));
    $address->lastname = 'Alami';
    check('a name with Latin letters is accepted', services()->paymentRequest()->hasValidName($address));
};

$scenarios['admin-panel'] = static function () {
    $o = newOrder();
    pay($o['tx'], 'approve');
    $context = Context::getContext();
    $context->employee = new Employee(1);
    $context->cookie = new Cookie('psAdmin');
    $panel = new Cmi\PrestaShop\Admin\OrderPanel(Module::getInstanceByName('cmi'), services(), $context);
    $vars = $panel->variables(new Order((int) $o['tx']->id_order));
    check('order panel: refund offered for a captured payment', $vars['can_refund'] && !$vars['can_confirm'] && $vars['state'] === PaymentState::CONFIRMED);
    $context->smarty->assign(['cmi' => $vars]);
    $html = $context->smarty->fetch(_PS_MODULE_DIR_ . 'cmi/views/templates/admin/order_panel.tpl');
    check('order panel template renders', strpos($html, 'cmi-payment-panel') !== false && strpos($html, $o['tx']->oid) !== false && strpos($html, 'cmi-refund-form') !== false, substr($html, 0, 300));
};

$names = array_slice($argv, 1) ?: array_keys($scenarios);
foreach ($names as $name) {
    echo "[$name]\n";
    try {
        $scenarios[$name]();
    } catch (Throwable $e) {
        check("$name ran without error", false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
}
echo sprintf("\n%d checks passed, %d failed (PrestaShop %s, PHP %s)\n", $results['passed'], count($results['failed']), _PS_VERSION_, PHP_VERSION);
exit($results['failed'] ? 1 : 0);
