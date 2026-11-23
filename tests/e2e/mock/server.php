<?php

/**
 * Mock of CMI for the test bench: hosted payment page, server callback, XML API, plus test
 * helpers (/mock/*). It reuses the module's signature code, so it signs exactly like CMI's
 * examples. Never deploy it: it accepts any card.
 */
define('_PS_VERSION_', 'mock');
require '/app/src/Protocol/CmiProtocol.php';

use Cmi\PrestaShop\Protocol\CmiProtocol;

const STATE_FILE = '/tmp/cmi-mock-state.json';

$storeKey = (string) getenv('CMI_STORE_KEY');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/** Read-modify-write of the shared state, under a lock. */
function state(?callable $change = null): array
{
    $handle = fopen(STATE_FILE, 'c+');
    flock($handle, LOCK_EX);
    $data = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
    if ($change) {
        $data = $change($data);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data, JSON_PRETTY_PRINT));
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    return $data;
}

function cmiNow(int $offset = 0): string
{
    return (new DateTimeImmutable('@' . (time() + $offset)))->setTimezone(new DateTimeZone('Africa/Casablanca'))->format('Y-m-d H:i:s') . '.000';
}

function newId(string $prefix): string
{
    return $prefix . strtoupper(bin2hex(random_bytes(5)));
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** POST to a shop, reaching it by its address inside the Docker network. */
function postToShop(string $url, array $fields): array
{
    $parts = parse_url($url);
    $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $map = [];
    foreach (explode(',', (string) getenv('SHOP_HOSTS')) as $pair) {
        [$public, $internal] = array_pad(explode('=', $pair, 2), 2, '');
        $map[$public] = $internal;
    }
    $target = 'http://' . ($map[$host] ?? $host) . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $curl = curl_init($target);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Host: ' . $host],
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return ['status' => $status, 'body' => trim($body)];
}

function addOperation(string $oid, array $operation): void
{
    state(static function (array $data) use ($oid, $operation): array {
        $data['orders'][$oid]['ops'][] = $operation;

        return $data;
    });
}

/** The payment operation (CHARGE_TYPE_CD S) of an OrderId, and its index. */
function paymentOperation(array $ops): array
{
    foreach (array_reverse($ops, true) as $index => $op) {
        if ($op['type'] === 'S') {
            return [$index, $op];
        }
    }

    return [null, null];
}

function xmlAnswer(array $fields, array $extra = [], array $history = []): void
{
    header('Content-Type: text/xml; charset=UTF-8');
    $xml = '<?xml version="1.0" encoding="ISO-8859-9"?><CC5Response>';
    foreach ($fields as $name => $value) {
        $xml .= '<' . $name . '>' . htmlspecialchars((string) $value, ENT_XML1) . '</' . $name . '>';
    }
    if ($extra || $history) {
        $xml .= '<Extra>';
        foreach ($extra as $name => $value) {
            $xml .= '<' . $name . '>' . htmlspecialchars((string) $value, ENT_XML1) . '</' . $name . '>';
        }
        foreach (array_values($history) as $i => $line) {
            $xml .= '<TRX' . ($i + 1) . '>' . htmlspecialchars($line, ENT_XML1) . '</TRX' . ($i + 1) . '>';
        }
        $xml .= '</Extra>';
    }
    echo $xml . '</CC5Response>';
    exit;
}

// ── Payment page ─────────────────────────────────────────────────────────────
if ($path === '/fim/est3Dgate') {
    $fields = $_POST;
    if (!hash_equals(CmiProtocol::requestHash($fields, $storeKey), (string) ($fields['hash'] ?? ''))) {
        http_response_code(400);
        echo '<h1>Mock CMI: invalid request signature</h1><pre>' . h(json_encode(array_keys($fields))) . '</pre>';
        exit;
    }
    $oid = (string) $fields['oid'];
    state(static function (array $data) use ($oid, $fields): array {
        $data['orders'][$oid]['request'] = $fields;
        $data['orders'][$oid]['ops'] = $data['orders'][$oid]['ops'] ?? [];

        return $data;
    });
    $converted = isset($fields['amountCur']) ? ' (' . h($fields['amountCur']) . ' ' . h($fields['symbolCur']) . ')' : '';
    echo '<!doctype html><meta charset="utf-8"><title>Mock CMI payment</title>'
        . '<body style="font-family:sans-serif;max-width:640px;margin:40px auto">'
        . '<h1>Mock CMI payment page</h1>'
        . '<p id="summary">OrderId <b id="oid">' . h($oid) . '</b> &middot; <b id="amount">' . h($fields['amount']) . '</b> (currency ' . h($fields['currency']) . ')' . $converted . ' &middot; client ' . h($fields['clientid']) . ' &middot; ' . h($fields['storetype']) . ' &middot; lang ' . h($fields['lang']) . '</p>'
        . '<p>Customer: ' . h($fields['BillToName'] ?? '') . ' &middot; ' . h($fields['email'] ?? '') . ' &middot; tel ' . h($fields['tel'] ?? '') . '</p>'
        . '<form method="post" action="/mock/pay"><input type="hidden" name="oid" value="' . h($oid) . '">'
        . '<button name="outcome" value="approve" id="approve">Approve</button> '
        . '<button name="outcome" value="decline" id="decline">Decline</button> '
        . '<button name="outcome" value="cancel" id="cancel">Cancel</button>'
        . '<p><label><input type="checkbox" name="no_callback" value="1"> Lose the server callback</label></p>'
        . '</form></body>';
    exit;
}

if ($path === '/mock/pay') {
    $oid = (string) ($_POST['oid'] ?? '');
    $outcome = (string) ($_POST['outcome'] ?? '');
    $data = state();
    $request = $data['orders'][$oid]['request'] ?? null;
    if (!$request) {
        http_response_code(404);
        exit('unknown oid');
    }
    if ($outcome === 'cancel') {
        header('Location: ' . $request['shopurl']);
        exit;
    }
    $approved = $outcome === 'approve';
    $transId = newId('T');
    $result = $request;
    unset($result['hash']);
    $result += [];
    $result['Response'] = $approved ? 'Approved' : 'Declined';
    $result['ProcReturnCode'] = $approved ? '00' : '05';
    $result['ErrMsg'] = $approved ? '' : 'Not sufficient funds';
    $result['TransId'] = $transId;
    $result['AuthCode'] = $approved ? (string) random_int(100000, 999999) : '';
    $result['HostRefNum'] = (string) random_int(100000000000, 999999999999);
    $result['ReturnOid'] = $oid;
    $result['MaskedPan'] = '411111***1111';
    // CMI names its extra fields EXTRA.*; PHP receives them as EXTRA_* (dots become
    // underscores), which keeps their order among themselves, so the signature still matches.
    $result['EXTRA.CARDBRAND'] = 'VISA';
    $result['mdStatus'] = '1';
    $result['EXTRA.TRXDATE'] = date('Ymd H:i:s');
    $result['HASH'] = CmiProtocol::callbackHash($result, $storeKey);
    $minor = (int) round((float) $request['amount'] * 100);
    addOperation($oid, ['type' => 'S', 'stat' => $approved ? 'A' : 'D', 'amount' => $minor, 'capture' => 0,
        'date' => cmiNow(), 'transid' => $transId, 'hostref' => $result['HostRefNum'], 'auth' => $result['AuthCode']]);

    $callback = null;
    if (empty($_POST['no_callback']) && ($request['CallbackResponse'] ?? '') === 'true' && !empty($request['callbackUrl'])) {
        $callback = postToShop($request['callbackUrl'], $result);
        if ($approved && $callback['body'] === 'ACTION=POSTAUTH') {
            // CMI captures by itself, under a new TransId.
            state(static function (array $data) use ($oid, $minor): array {
                foreach ($data['orders'][$oid]['ops'] as &$op) {
                    if ($op['type'] === 'S' && $op['stat'] === 'A') {
                        $op['stat'] = 'C';
                        $op['capture'] = $minor;
                        $op['transid'] = newId('P');
                    }
                }

                return $data;
            });
        }
    }
    state(static function (array $data) use ($oid, $result, $callback): array {
        $data['orders'][$oid]['last_result'] = $result;
        $data['orders'][$oid]['callbacks'][] = $callback;

        return $data;
    });
    $target = $approved ? $request['okUrl'] : $request['failUrl'];
    echo '<!doctype html><meta charset="utf-8"><title>Mock CMI result</title><body style="font-family:sans-serif;max-width:640px;margin:40px auto">'
        . '<p id="callback">Callback answer: <b>' . h($callback ? $callback['status'] . ' ' . $callback['body'] : 'none') . '</b></p>'
        . '<form id="back" method="post" action="' . h($target) . '">';
    foreach ($result as $name => $value) {
        echo '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    echo '<button id="return">Return to the shop</button></form>';
    if (($request['AutoRedirect'] ?? 'true') === 'true') {
        echo '<script>setTimeout(function(){document.getElementById("back").submit();}, 800);</script>';
    }
    exit;
}

// ── XML API ──────────────────────────────────────────────────────────────────
if ($path === '/fim/api') {
    $xml = simplexml_load_string((string) file_get_contents('php://input'));
    if (!$xml || (string) $xml->Name !== getenv('CMI_API_USER') || (string) $xml->Password !== getenv('CMI_API_PASSWORD')) {
        xmlAnswer(['Response' => 'Error', 'ProcReturnCode' => '99', 'ErrMsg' => 'Authentication failed']);
    }
    $oid = (string) $xml->OrderId;
    $type = isset($xml->Extra->ORDERSTATUS) ? 'ORDERSTATUS' : (isset($xml->Extra->ORDERHISTORY) ? 'ORDERHISTORY' : (string) $xml->Type);
    $data = state();
    $ops = $data['orders'][$oid]['ops'] ?? [];
    state(static function (array $data) use ($oid, $type): array {
        $data['api_log'][] = $type . ' ' . $oid;

        return $data;
    });

    if ($type === 'ORDERSTATUS') {
        // The last successful transaction (or the last attempt when none succeeded).
        $last = null;
        foreach ($ops as $op) {
            if ($op['stat'] !== 'D' || $last === null || $last['stat'] === 'D') {
                $last = $op;
            }
        }
        if ($last === null) {
            xmlAnswer(['ErrMsg' => 'No record found for ' . $oid, 'ProcReturnCode' => '99', 'Response' => 'Declined', 'OrderId' => $oid]);
        }
        xmlAnswer(
            ['ErrMsg' => 'Record(s) found for ' . $oid, 'ProcReturnCode' => $last['stat'] === 'D' ? '05' : '00', 'Response' => 'Approved', 'OrderId' => $oid, 'TransId' => $last['transid']],
            ['AUTH_CODE' => $last['auth'], 'HOST_REF_NUM' => $last['hostref'], 'AUTH_DTTM' => $last['date'], 'TRANS_ID' => $last['transid'],
                'CHARGE_TYPE_CD' => $last['type'], 'TRANS_STAT' => $last['stat'], 'ORIG_TRANS_AMT' => $last['amount'], 'CAPTURE_AMT' => $last['capture']]
        );
    }
    if ($type === 'ORDERHISTORY') {
        $lines = array_map(static function (array $op): string {
            return implode(' ', [$op['type'], $op['stat'], $op['amount'], $op['capture'], $op['date'], $op['date'], $op['hostref'], $op['auth'] ?: '1', '00', $op['transid']]);
        }, $ops);
        xmlAnswer(['ErrMsg' => '', 'ProcReturnCode' => '00', 'Response' => 'Approved', 'OrderId' => $oid], ['TRXCOUNT' => count($lines)], $lines);
    }

    [$index, $payment] = paymentOperation($ops);
    $transId = newId('X');
    $decline = static function (string $message) use ($oid) {
        xmlAnswer(['OrderId' => $oid, 'Response' => 'Declined', 'ProcReturnCode' => '99', 'ErrMsg' => $message]);
    };
    if ($payment === null) {
        $decline('No such order');
    }
    if ($type === 'PostAuth') {
        if ($payment['stat'] !== 'A') {
            $decline('Not a preauthorization');
        }
        $total = isset($xml->Total) ? (int) round((float) $xml->Total * 100) : $payment['amount'];
        state(static function (array $data) use ($oid, $index, $total, $transId): array {
            $data['orders'][$oid]['ops'][$index]['stat'] = 'C';
            $data['orders'][$oid]['ops'][$index]['capture'] = $total;
            $data['orders'][$oid]['ops'][$index]['transid'] = $transId;

            return $data;
        });
    } elseif ($type === 'Void') {
        if ($payment['stat'] !== 'A') {
            $decline('Cannot void');
        }
        state(static function (array $data) use ($oid, $index, $transId): array {
            $data['orders'][$oid]['ops'][$index]['stat'] = 'V';
            $data['orders'][$oid]['ops'][$index]['transid'] = $transId;

            return $data;
        });
    } elseif ($type === 'Credit') {
        $refunded = 0;
        foreach ($ops as $op) {
            if ($op['type'] === 'C' && $op['stat'] === 'C') {
                $refunded += $op['capture'];
            }
        }
        $amount = (int) round((float) $xml->Total * 100);
        if (!in_array($payment['stat'], ['C', 'S'], true) || $amount <= 0 || $refunded + $amount > $payment['capture']) {
            $decline('Credit amount exceeds captured amount');
        }
        if ((string) $xml->Currency !== ($data['orders'][$oid]['request']['currency'] ?? '504')) {
            $decline('Wrong currency');
        }
        addOperation($oid, ['type' => 'C', 'stat' => 'C', 'amount' => $amount, 'capture' => $amount, 'date' => cmiNow(),
            'transid' => $transId, 'hostref' => (string) random_int(100000000000, 999999999999), 'auth' => '']);
    } else {
        $decline('Unknown type ' . $type);
    }
    xmlAnswer(['OrderId' => $oid, 'Response' => 'Approved', 'ProcReturnCode' => '00', 'ErrMsg' => '', 'TransId' => $transId, 'HostRefNum' => (string) random_int(100000000000, 999999999999), 'AuthCode' => (string) random_int(100000, 999999)]);
}

// ── Test helpers ─────────────────────────────────────────────────────────────
header('Content-Type: application/json');
if ($path === '/mock/state') {
    $data = state();
    $oid = (string) ($_GET['oid'] ?? '');
    echo json_encode($oid !== '' ? ($data['orders'][$oid] ?? null) : $data);
    exit;
}
if ($path === '/mock/back-office') {
    // Simulates an operation made by the merchant in the CMI back office.
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $oid = (string) $input['oid'];
    $data = state();
    [$index, $payment] = paymentOperation($data['orders'][$oid]['ops'] ?? []);
    if ($input['action'] === 'refund') {
        addOperation($oid, ['type' => 'C', 'stat' => 'C', 'amount' => (int) $input['amount'], 'capture' => (int) $input['amount'], 'date' => cmiNow(), 'transid' => newId('B'), 'hostref' => '1', 'auth' => '']);
    } else {
        state(static function (array $data) use ($oid, $index, $input): array {
            $data['orders'][$oid]['ops'][$index]['stat'] = $input['action'] === 'capture' ? 'C' : 'V';
            $data['orders'][$oid]['ops'][$index]['capture'] = $data['orders'][$oid]['ops'][$index]['amount'];
            $data['orders'][$oid]['ops'][$index]['transid'] = newId('B');

            return $data;
        });
    }
    echo json_encode(state()['orders'][$oid]);
    exit;
}
if ($path === '/mock/seed') {
    // Another website's transaction under an OrderId (collision test).
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
    addOperation((string) $input['oid'], ['type' => 'S', 'stat' => 'C', 'amount' => (int) $input['amount'], 'capture' => (int) $input['amount'],
        'date' => cmiNow(-86400 * (int) ($input['days_ago'] ?? 30)), 'transid' => newId('F'), 'hostref' => '2', 'auth' => '777777']);
    echo json_encode(state()['orders'][(string) $input['oid']]);
    exit;
}
if ($path === '/mock/sign') {
    // Sign arbitrary callback fields with the store key (harness).
    $fields = json_decode((string) file_get_contents('php://input'), true) ?: [];
    unset($fields['HASH']);
    $fields['HASH'] = CmiProtocol::callbackHash($fields, $storeKey);
    echo json_encode($fields);
    exit;
}
http_response_code(404);
echo '{"error":"not found"}';
