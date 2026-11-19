<?php
/**
 * Unit tests of the module's PrestaShop-independent rules.
 *
 * Run with: php tests/run-tests.php
 * They load the module's own classes (not copies), so a change to a rule fails here.
 */

// The module's files only run inside PrestaShop (_PS_VERSION_); these tests load them on purpose.
define('_PS_VERSION_', 'tests');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Cmi\\PrestaShop\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Cmi\PrestaShop\Payment\AccountResolver;
use Cmi\PrestaShop\Payment\Amounts;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Payment\TransactionGuard;
use Cmi\PrestaShop\Protocol\ApiException;
use Cmi\PrestaShop\Protocol\ApiResponseParser;
use Cmi\PrestaShop\Protocol\CmiProtocol;

$passed = 0;
$failures = [];

function check(string $label, bool $condition): void
{
    global $passed, $failures;
    if ($condition) {
        ++$passed;
    } else {
        $failures[] = $label;
    }
}

function same(string $label, $expected, $actual): void
{
    global $passed, $failures;
    if ($expected === $actual) {
        ++$passed;
    } else {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
}

// ── 1. Signatures ──────────────────────────────────────────────────────────────
// Known answers computed with CMI's PHP examples (2.SendData.php for the request, callback.php
// for the callback): natural order, "|" and "\" escaped, HTML entities decoded.
$key = 'Key|With\\Pipe';
$request = [
    'clientid' => '600000000', 'amount' => '1250.00', 'oid' => 'WC69DF-31', 'okUrl' => 'https://shop.ma/?wc-api=WC_Gateway_cmi_return',
    'BillToName' => 'Said El Idrissi', 'BillToStreet1' => ' 15 rue | Ibn &amp;amp; Battouta ', 'currency' => '504', 'rnd' => 'abc123',
    'id10' => 'x', 'id2' => 'y', 'encoding' => 'UTF-8', 'hash' => 'old', 'description' => 'C:\\path',
];
$callback = $request + ['Response' => 'Approved', 'ProcReturnCode' => '00', 'ErrMsg' => "Line\n", 'HASH' => 'zzz', 'EXTRA.TRXDATE' => '20261105 10:00:00', 'Note' => 'l&#039;ordre &eacute;t&eacute;'];
same('request signature matches CMI example', 'IdDy9ygtwjbfMtXFRYhhk8vjxG8Ifek+PZCf/42CvFq4y2n8hdJj7J9JEFkZk5YiO1Sc3xNxdU98j8CHe0j+Ag==', CmiProtocol::requestHash($request, $key));
same('callback signature matches CMI example', 'M+xLM+4LHpELGHl7FMclfOibjhOf3vK4WRGa4VOkK1UqEhi/eqPlIVm9Nt2mQd65IGBsgAK4WP2mse2u44+iyA==', CmiProtocol::callbackHash($callback, $key));

$signed = ['oid' => 'PS1A2B-XKBKNABJK', 'amount' => '150.00', 'ProcReturnCode' => '00', 'Response' => 'Approved'];
$signed['HASH'] = CmiProtocol::callbackHash($signed, 'secret');
check('a correctly signed callback is accepted', CmiProtocol::isValidCallback($signed, 'secret'));
check('a callback signed with another key is refused', !CmiProtocol::isValidCallback($signed, 'other'));
$tampered = $signed;
$tampered['amount'] = '1.00';
check('a tampered amount is refused', !CmiProtocol::isValidCallback($tampered, 'secret'));
$noHash = $signed;
unset($noHash['HASH']);
check('a callback without HASH is refused', !CmiProtocol::isValidCallback($noHash, 'secret'));
check('an empty store key never validates', !CmiProtocol::isValidCallback($signed, ''));
$lowerHash = $signed;
$lowerHash['hash'] = $lowerHash['HASH'];
unset($lowerHash['HASH']);
check('the hash field name is case-insensitive', CmiProtocol::isValidCallback($lowerHash, 'secret'));
same('hash and encoding are left out of the digest', CmiProtocol::requestHash(['a' => '1'], 'k'), CmiProtocol::requestHash(['a' => '1', 'HASH' => 'x', 'encoding' => 'UTF-8'], 'k'));

// ── 2. Values sent to CMI ──────────────────────────────────────────────────────
same('accents removed from a name', 'Jose Martin', CmiProtocol::withoutAccents('José Martín'));
same('ligatures and slashed letters kept as Latin letters', 'AEsir Olaf', CmiProtocol::withoutAccents('Æsir Ølaf'));
same('a name in Arabic has no Latin letter left', '', CmiProtocol::withoutAccents('محمد العلوي'));
check('Arabic-only name is detected', !CmiProtocol::hasLatinName('محمد العلوي'));
check('Latin name is accepted', CmiProtocol::hasLatinName('Mohamed Alami'));
check('mixed name is accepted', CmiProtocol::hasLatinName('محمد Alami'));
same('international phone keeps its +', '+212 600000000', CmiProtocol::cleanPhone(' +212 600-000-000 '));
same('local phone', '0600000000', CmiProtocol::cleanPhone('06.00.00.00.00'));
same('OrderId keeps letters, digits, - and _', 'CMD-2026_01', CmiProtocol::sanitizeOid('CMD-2026_01'));
same('OrderId loses spaces and punctuation', 'CMD20260001', CmiProtocol::sanitizeOid('CMD / 2026 # 0001!'));
same('OrderId accents removed', 'COMMANDE-ETE', CmiProtocol::sanitizeOid('COMMANDE-ÉTÉ'));
same('OrderId limited to 64 characters', 64, strlen(CmiProtocol::sanitizeOid(str_repeat('A', 80))));
same('amount format', '1080.50', CmiProtocol::formatAmount(1080.5));
same('amount format rounds', '0.10', CmiProtocol::formatAmount(0.1 + 0.2 - 0.2));

// ── 3. XML requests ───────────────────────────────────────────────────────────
$evil = '1001</OrderId><Injected>x</Injected><OrderId>1001';
$xml = (string) CmiProtocol::xmlRequest('PostAuth', 'admin', 'p&ss<1>', 'client"1"', $evil);
check('PostAuth escapes injected tags', strpos($xml, '&lt;Injected&gt;') !== false);
$parsed = simplexml_load_string($xml);
check('PostAuth is well-formed', $parsed !== false);
same('PostAuth type', 'PostAuth', (string) $parsed->Type);
check('full PostAuth has no Total', !isset($parsed->Total));
same('partial PostAuth Total', '50.00', (string) simplexml_load_string((string) CmiProtocol::xmlRequest('PostAuth', 'a', 'b', 'c', '1', '50.00'))->Total);
$void = simplexml_load_string((string) CmiProtocol::xmlRequest('Void', 'a', 'b', 'c', '1'));
check('Void has no Total or Currency', !isset($void->Total) && !isset($void->Currency));
$credit = simplexml_load_string((string) CmiProtocol::xmlRequest('Credit', 'a', 'b', 'c', '1', '150.00', '978'));
same('Credit currency', '978', (string) $credit->Currency);
same('Credit total', '150.00', (string) $credit->Total);
same('ORDERSTATUS query', 'QUERY', (string) simplexml_load_string((string) CmiProtocol::xmlRequest('ORDERSTATUS', 'a', 'b', 'c', '1'))->Extra->ORDERSTATUS);
same('ORDERHISTORY query', 'QUERY', (string) simplexml_load_string((string) CmiProtocol::xmlRequest('ORDERHISTORY', 'a', 'b', 'c', '1'))->Extra->ORDERHISTORY);
same('unknown request type', null, CmiProtocol::xmlRequest('Sale', 'a', 'b', 'c', '1'));

// ── 4. API answers ────────────────────────────────────────────────────────────
$sample = '<CC5Response>
    <ErrMsg>Record(s) found for ORDER-16056Q4hJ17661</ErrMsg>
    <ProcReturnCode>00</ProcReturnCode>
    <Response>Approved</Response>
    <OrderId>ORDER-16056Q4hJ17661</OrderId>
    <TransId>16056Q6HA17676</TransId>
    <Extra>
        <AUTH_CODE>073085</AUTH_CODE>
        <HOST_REF_NUM>605616000032</HOST_REF_NUM>
        <AUTH_DTTM>2016-02-25 16:56:06.995</AUTH_DTTM>
        <TRANS_ID>16056Q6HA17676</TRANS_ID>
        <CHARGE_TYPE_CD>S</CHARGE_TYPE_CD>
        <TRANS_STAT>C</TRANS_STAT>
        <ORIG_TRANS_AMT>9196</ORIG_TRANS_AMT>
        <CAPTURE_AMT>9196</CAPTURE_AMT>
    </Extra>
</CC5Response>';
$status = ApiResponseParser::parse($sample);
same('parser: Response', 'Approved', $status['Response']);
same('parser: TRANS_STAT', 'C', $status['TRANS_STAT']);
same('parser: AuthCode from Extra', '073085', $status['AuthCode']);
same('parser: HostRefNum from Extra', '605616000032', $status['HostRefNum']);
same('parser: ORIG_TRANS_AMT', '9196', $status['ORIG_TRANS_AMT']);
$history = ApiResponseParser::parse('<CC5Response><Response>Approved</Response><ProcReturnCode>00</ProcReturnCode><Extra>'
    . '<TRX1>S C 10000 10000 2026-10-29 10:01:00.000 2026-10-29 10:05:00.000 730214004948 123456 00 17201OSAE17750</TRX1>'
    . '<TRX2>C C 3000 3000 2026-10-30 09:00:00.000 2026-10-30 09:00:00.000 730314004950 1 00 17201OUPB17934</TRX2>'
    . '</Extra></CC5Response>');
same('parser: history lines', 2, count($history['HISTORY']));
$inline = ApiResponseParser::parse('<CC5Response><Response>Approved</Response><ProcReturnCode>00</ProcReturnCode><Extra><ORDERSTATUS>ORD_ID:X TRANS_STAT:A CHARGE_TYPE_CD:S ORIG_TRANS_AMT:15000 AUTH_DTTM:2026-10-29 10:01:00.000 TRANS_ID:ABC123</ORDERSTATUS></Extra></CC5Response>');
same('parser: TRANS_STAT from ORDERSTATUS text', 'A', $inline['TRANS_STAT']);
same('parser: amount from ORDERSTATUS text', '15000', $inline['ORIG_TRANS_AMT']);
same('parser: TransId from ORDERSTATUS text', 'ABC123', $inline['TransId']);
$broken = ApiResponseParser::parse("garbage<Response>Declined</Response><ProcReturnCode>99</ProcReturnCode><ErrMsg>Order not found</ErrMsg>");
same('parser: tag fallback on broken XML', '99', $broken['ProcReturnCode']);
foreach (['' => 'empty_response', '<html><body>Login</body></html>' => 'html_response', '<x>nothing</x>' => 'invalid_response'] as $body => $reason) {
    try {
        ApiResponseParser::parse($body);
        $failures[] = 'parser should refuse: ' . $reason;
    } catch (ApiException $e) {
        same('parser refuses ' . $reason, $reason, $e->getReason());
    }
}
try {
    ApiResponseParser::parse('<!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><CC5Response><Response>&x;</Response><ProcReturnCode>00</ProcReturnCode></CC5Response>');
    $failures[] = 'parser should refuse a DOCTYPE (external entities)';
} catch (ApiException $e) {
    same('parser refuses a DOCTYPE (external entities)', 'invalid_response', $e->getReason());
}

// ── 5. Payment states ─────────────────────────────────────────────────────────
same('approved, automatic', PaymentState::CONFIRMED, PaymentState::fromCallback('00', 'automatic'));
same('approved, manual', PaymentState::PRE_CONFIRMATION_REQUIRED, PaymentState::fromCallback('00', 'manual'));
foreach (['05', '51', '99', '12', '01'] as $code) {
    same('declined ' . $code, PaymentState::FAILED, PaymentState::fromCallback($code, 'automatic'));
}
check('only a pre-authorization can be confirmed', PaymentState::canConfirm(PaymentState::PRE_CONFIRMATION_REQUIRED) && !PaymentState::canConfirm(PaymentState::CONFIRMED) && !PaymentState::canConfirm(PaymentState::INITIATED));
check('only a captured payment can be refunded', PaymentState::canRefund(PaymentState::CONFIRMED) && !PaymentState::canRefund(PaymentState::PRE_CONFIRMATION_REQUIRED) && !PaymentState::canRefund(PaymentState::REFUNDED));
check('a decline never overwrites a pre-authorization', !PaymentState::acceptsCallback(PaymentState::PRE_CONFIRMATION_REQUIRED, false));
check('a decline never overwrites a capture', !PaymentState::acceptsCallback(PaymentState::CONFIRMED, false));
check('a decline updates an initiated payment', PaymentState::acceptsCallback(PaymentState::INITIATED, false));
check('an approval after a decline is accepted (customer tried again)', PaymentState::acceptsCallback(PaymentState::FAILED, true));
check('an approval never reopens a voided payment', !PaymentState::acceptsCallback(PaymentState::VOIDED, true));
check('an approval never reopens a refunded payment', !PaymentState::acceptsCallback(PaymentState::REFUNDED, true));
check('awaiting payment', PaymentState::isAwaitingPayment(PaymentState::INITIATED) && PaymentState::isAwaitingPayment(PaymentState::FAILED) && !PaymentState::isAwaitingPayment(PaymentState::CONFIRMED));
same('pre-authorization of 4 days: no warning', 0, PaymentState::preauthWarningDays(1000, 1000 + 4 * 86400));
same('pre-authorization of 6 days: warning', 6, PaymentState::preauthWarningDays(1000, 1000 + 6 * 86400 + 60));
same('unknown pre-authorization date: no warning', 0, PaymentState::preauthWarningDays(0, 99999999));
same('first check after 15 minutes', 900, PaymentState::pendingCheckDelay(0));
same('no check after the third', null, PaymentState::pendingCheckDelay(3));
check('a pre-authorization found by a check applies', PaymentState::pendingCheckApplies('A'));
check('a decline applies only at the last check', !PaymentState::pendingCheckApplies('D') && PaymentState::pendingCheckApplies('D', true));
check('not found never applies', !PaymentState::pendingCheckApplies('', true));
check('customer cancel allowed without payment', PaymentState::customerCancelAllowed('NOT_FOUND') && PaymentState::customerCancelAllowed(''));
check('customer cancel refused with a payment', !PaymentState::customerCancelAllowed('PRE_AUTHORIZED') && !PaymentState::customerCancelAllowed('APPROVED') && !PaymentState::customerCancelAllowed('ERROR'));
check('captured TRANS_STAT', PaymentState::isCapturedTransStat('C') && PaymentState::isCapturedTransStat('s') && !PaymentState::isCapturedTransStat('A'));

// ── 6. Ownership guard ────────────────────────────────────────────────────────
$sentAt = TransactionGuard::parseCmiDatetime('2026-10-29 10:00:00');
$oursCallback = ['ids' => ['17201OMPE17205'], 'amount_minor' => 10000, 'sent_at' => $sentAt];
$oursPostAuth = ['ids' => ['17201OMPE17205', '17201OSAE17750'], 'amount_minor' => 10000, 'sent_at' => $sentAt];
$statusOurs = ['TransId' => '17201OMPE17205', 'TRANS_STAT' => 'A', 'CHARGE_TYPE_CD' => 'S', 'ORIG_TRANS_AMT' => '10000', 'AUTH_DTTM' => '2026-10-29 10:01:00.000'];
same('own pre-authorization recognised by its TransId', TransactionGuard::OWNED, TransactionGuard::ownership($statusOurs, [], $oursCallback));
$afterPostAuth = ['TransId' => '17201OSAE17750', 'TRANS_STAT' => 'C', 'CHARGE_TYPE_CD' => 'S', 'ORIG_TRANS_AMT' => '10000', 'AUTH_DTTM' => '2026-10-29 10:05:00.000'];
same('captured by CMI itself: amount and date match', TransactionGuard::OWNED, TransactionGuard::ownership($afterPostAuth, [], $oursCallback));
$afterRefund = ['TransId' => '17201OUPB17934', 'TRANS_STAT' => 'C', 'CHARGE_TYPE_CD' => 'C', 'ORIG_TRANS_AMT' => '3000'];
same('the Credit alone cannot be matched', TransactionGuard::FOREIGN, TransactionGuard::ownership($afterRefund, [], $oursPostAuth));
same('the history with our PostAuth proves ownership', TransactionGuard::OWNED, TransactionGuard::ownership($afterRefund, $history['HISTORY'], $oursPostAuth));
$foreign = ['TransId' => '16056Q6HA17676', 'HostRefNum' => '605616000032', 'TRANS_STAT' => 'C', 'CHARGE_TYPE_CD' => 'S', 'ORIG_TRANS_AMT' => '9196', 'AUTH_DTTM' => '2016-02-25 16:56:06.995'];
$foreignHistory = ['S C 9196 9196 2016-02-25 16:56:06.995 2016-02-25 16:56:06.995 605616000032 073085 00 16056Q6HA17676'];
same('another website\'s payment is refused', TransactionGuard::FOREIGN, TransactionGuard::ownership($foreign, $foreignHistory, $oursCallback));
same('another website\'s older payment with the same amount is refused', TransactionGuard::FOREIGN, TransactionGuard::ownership($foreign, $foreignHistory, ['ids' => [], 'amount_minor' => 9196, 'sent_at' => $sentAt]));
same('nothing to compare: unverified', TransactionGuard::UNVERIFIED, TransactionGuard::ownership($statusOurs, [], ['ids' => [], 'amount_minor' => null, 'sent_at' => null]));
same('older order without send time: the amount decides', TransactionGuard::OWNED, TransactionGuard::ownership($foreign, [], ['ids' => [], 'amount_minor' => 9196, 'sent_at' => null]));
$oneHourBefore = $statusOurs;
$oneHourBefore['TransId'] = 'OTHER';
$oneHourBefore['AUTH_DTTM'] = '2026-10-29 09:00:00.000';
same('one hour apart (time zone): still ours', TransactionGuard::OWNED, TransactionGuard::ownership($oneHourBefore, [], ['ids' => [], 'amount_minor' => 10000, 'sent_at' => $sentAt]));
$oneHourBefore['AUTH_DTTM'] = '2026-10-29 07:00:00.000';
same('three hours before sending: refused', TransactionGuard::FOREIGN, TransactionGuard::ownership($oneHourBefore, [], ['ids' => [], 'amount_minor' => 10000, 'sent_at' => $sentAt]));
check('unknown OrderId is not found', TransactionGuard::isNotFound(['Response' => 'Error', 'ProcReturnCode' => '', 'ErrMsg' => 'Order not found']));
check('answer without transaction data is not found', TransactionGuard::isNotFound(['Response' => 'Approved', 'ProcReturnCode' => '99', 'ErrMsg' => 'No record found']));
check('a real transaction is found', !TransactionGuard::isNotFound($foreign));
same('confirm allowed on a pre-authorization', '', TransactionGuard::actionBlockReason('confirm', 'A', 'S'));
same('confirm refused when captured', 'already_captured', TransactionGuard::actionBlockReason('confirm', 'C', 'S'));
same('void refused when settled', 'already_captured', TransactionGuard::actionBlockReason('void', 'S', 'S'));
same('refund allowed after a partial refund', '', TransactionGuard::actionBlockReason('refund', 'C', 'C'));
same('refund refused on a pre-authorization', 'not_captured', TransactionGuard::actionBlockReason('refund', 'A', 'S'));
same('missing TRANS_STAT blocks', 'unknown_state', TransactionGuard::actionBlockReason('refund', '', ''));
same('voided blocks', 'voided', TransactionGuard::actionBlockReason('confirm', 'V', 'S'));
$totals = TransactionGuard::historyTotals($history['HISTORY']);
same('history captured total', 10000, $totals['captured']);
same('history refunded total', 3000, $totals['refunded']);
same('a voided refund is not counted', 3000, TransactionGuard::historyTotals(array_merge($history['HISTORY'], ['C V 7000 7000 2026-10-31 09:00:00.000 2026-10-31 09:00:00.000 1 1 00 Z9']))['refunded']);
same('prefix cleaned', 'SHOP2-', TransactionGuard::sanitizeOidPrefix('SHOP 2-'));
same('prefix limited to 20', 20, strlen(TransactionGuard::sanitizeOidPrefix(str_repeat('A', 30))));
same('prefix applied', 'PS7F3A-XKBKNABJK', TransactionGuard::applyOidPrefix('PS7F3A-', 'XKBKNABJK'));
same('prefixed OrderId within 64', 64, strlen(TransactionGuard::applyOidPrefix('PS7F3A-', str_repeat('9', 80))));
check('generated prefix format', (bool) preg_match('/^PS[0-9A-F]{4}-$/', TransactionGuard::generateOidPrefix()));
check('store key with SKS refused', !TransactionGuard::isValidStoreKey('abcsks9') && TransactionGuard::isValidStoreKey('S-K-S'));
same('centimes', 108289, TransactionGuard::toMinorUnits(1082.89));

// ── 7. Amounts and currencies ─────────────────────────────────────────────────
same('100 EUR to MAD', 1080.0, Amounts::toCharged(100.0, 10.8));
same('MAD back to EUR', 100.0, Amounts::fromCharged(1080.0, 10.8));
same('zero rate never divides', 0.0, Amounts::fromCharged(100.0, 0.0));
same('rate from PrestaShop rates (MAD default)', round(1 / 0.0926, 6), round(Amounts::rateBetween(0.0926, 1.0, false), 6));
same('rate from PrestaShop rates (EUR default)', 10.8, Amounts::rateBetween(1.0, 10.8, false));
same('rate of exactly 1 between two currencies is refused', 0.0, Amounts::rateBetween(1.0, 1.0, false));
same('same currency', 1.0, Amounts::rateBetween(1.0, 1.0, true));
same('zero rate refused', 0.0, Amounts::rateBetween(0.0, 10.8, false));
$full = Amounts::splitRefund(1080.0, 1080.0, 100.0, 10.8);
check('full refund', $full['full'] && $full['charged'] === 1080.0 && $full['order'] === 100.0);
$partial = Amounts::splitRefund(540.0, 1080.0, 100.0, 10.8);
check('partial refund', !$partial['full'] && $partial['charged'] === 540.0 && $partial['order'] === 50.0);
$rate = 10.8291;
$total = Amounts::toCharged(99.99, $rate);
$first = Amounts::splitRefund(333.33, $total, 99.99, $rate);
$second = Amounts::splitRefund($total - $first['charged'], $total - $first['charged'], 99.99 - $first['order'], $rate);
same('two refunds add up exactly in the charged currency', $total, round($first['charged'] + $second['charged'], 2));
same('two refunds add up exactly in the order currency', 99.99, round($first['order'] + $second['order'], 2));
$balance = Amounts::balance(150.0, 40.0);
same('balance remaining', 110.0, $balance['remaining']);
same('refunded never exceeds total', 150.0, Amounts::balance(150.0, 200.0)['refunded']);
same('amount typed with comma', 10.5, Amounts::parse('10,50'));
same('negative amount refused', 0.0, Amounts::parse('-5'));
same('text refused', 0.0, Amounts::parse('abc'));

$madOnly = AccountResolver::resolve(['504'], 'EUR');
check('MAD-only store converts EUR to MAD', $madOnly['account'] === 'inshore' && $madOnly['currency'] === '504' && $madOnly['convert'] === true);
$madOrder = AccountResolver::resolve(['504'], 'MAD');
check('MAD order on MAD store', $madOrder['account'] === 'inshore' && $madOrder['convert'] === false);
$offshore = AccountResolver::resolve(['978', '840'], 'EUR');
check('offshore store charges EUR as EUR', $offshore['account'] === 'offshore' && $offshore['currency'] === '978' && $offshore['convert'] === false);
same('offshore store refuses a currency it does not accept', null, AccountResolver::resolve(['978'], 'GBP'));
same('offshore store refuses MAD', null, AccountResolver::resolve(['978'], 'MAD'));
$mixedEur = AccountResolver::resolve(['504', '978'], 'EUR');
check('mixed store: EUR on the offshore account', $mixedEur['account'] === 'offshore' && $mixedEur['currency'] === '978');
$mixedMad = AccountResolver::resolve(['504', '978'], 'MAD');
check('mixed store: MAD on the standard account', $mixedMad['account'] === 'inshore');
$mixedGbp = AccountResolver::resolve(['504', '978'], 'GBP');
check('mixed store: unlisted currency converted to MAD', $mixedGbp['account'] === 'inshore' && $mixedGbp['convert'] === true);
check('nothing selected behaves as MAD only', AccountResolver::resolve([], 'EUR')['currency'] === '504');
same('numeric code', '784', AccountResolver::numericCode('aed'));
same('alpha code', 'SAR', AccountResolver::alphaCode('682'));

// ── Result ────────────────────────────────────────────────────────────────────
echo sprintf("%d checks passed, %d failed.\n", $passed, count($failures));
foreach ($failures as $failure) {
    echo '  FAIL: ' . $failure . "\n";
}
exit($failures ? 1 : 0);
