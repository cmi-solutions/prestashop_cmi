<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Protocol;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reads the XML answers of CMI's API (PostAuth, Void, Credit, ORDERSTATUS, ORDERHISTORY).
 *
 * Returns a flat array of the known fields, plus HISTORY: one line per transaction of the
 * OrderId (ORDERHISTORY). No PrestaShop call in here.
 */
final class ApiResponseParser
{
    private const FIELDS = ['Response', 'ProcReturnCode', 'ErrMsg', 'OrderId', 'TransId', 'HostRefNum', 'AuthCode'];

    private const EXTRA_FIELDS = [
        'TRANS_STAT', 'CHARGE_TYPE_CD', 'AUTH_CODE', 'TRANS_ID', 'HOST_REF_NUM', 'CAPTURE_AMT',
        'ORIG_TRANS_AMT', 'CAPTURE_DTTM', 'AUTH_DTTM', 'PAN', 'ORDERSTATUS',
    ];

    /** Patterns reading values from the text of an <ORDERSTATUS> block. */
    private const INLINE = [
        'TRANS_STAT' => '/TRANS_STAT:([A-Za-z0-9_]+)/i',
        'CHARGE_TYPE_CD' => '/CHARGE_TYPE_CD:([A-Za-z0-9_]+)/i',
        'CAPTURE_AMT' => '/CAPTURE_AMT:([0-9.]+)/i',
        'ORIG_TRANS_AMT' => '/ORIG_TRANS_AMT:([0-9]+)/i',
        'AUTH_DTTM' => '/AUTH_DTTM:(\d{4}-\d{2}-\d{2} [0-9:.]+)/i',
        'TRANS_ID' => '/TRANS_ID:([A-Za-z0-9]+)/i',
    ];

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException when the body is not a CMI API answer
     */
    public static function parse(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new ApiException('empty_response', 'CMI API returned an empty response. Check the CMI API URL.');
        }
        if (stripos($body, '<html') !== false || stripos($body, '<!doctype html') !== false || stripos($body, '<head') !== false) {
            throw new ApiException('html_response', 'CMI API returned an HTML page instead of XML. The CMI API URL must be the XML API endpoint (it ends with /fim/api), not the payment page (est3Dgate).');
        }
        // CMI answers never declare a DOCTYPE or entities: refuse them (XML external entities).
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new ApiException('invalid_response', 'CMI API returned an XML document with a DOCTYPE, which CMI never sends.');
        }

        $result = array_fill_keys(array_merge(self::FIELDS, self::EXTRA_FIELDS), '');
        $result['HISTORY'] = [];
        if (preg_match_all('/<TRX(\d+)>(.*?)<\/TRX\1>/is', $body, $matches)) {
            foreach ($matches[2] as $line) {
                $result['HISTORY'][] = self::clean((string) preg_replace('/\s+/', ' ', $line));
            }
        }

        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch external resources named in the document.
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml !== false) {
            foreach (self::FIELDS as $field) {
                if (isset($xml->{$field})) {
                    $result[$field] = self::clean((string) $xml->{$field});
                }
            }
            if (isset($xml->Extra)) {
                foreach (self::EXTRA_FIELDS as $field) {
                    if (isset($xml->Extra->{$field})) {
                        $result[$field] = self::clean((string) $xml->Extra->{$field});
                    }
                }
            }
        } else {
            // Non-standard declaration or stray characters: read the tags one by one.
            foreach (array_merge(self::FIELDS, self::EXTRA_FIELDS) as $field) {
                if (preg_match('/<' . $field . '[^>]*>(.*?)<\/' . $field . '>/is', $body, $m)) {
                    $result[$field] = self::clean($m[1]);
                }
            }
        }

        if ($result['ORDERSTATUS'] !== '') {
            foreach (self::INLINE as $field => $pattern) {
                if ($result[$field] === '' && preg_match($pattern, $result['ORDERSTATUS'], $m)) {
                    $result[$field] = trim($m[1]);
                }
            }
        }
        $result['TRANS_STAT'] = strtoupper($result['TRANS_STAT']);
        $result['CHARGE_TYPE_CD'] = strtoupper($result['CHARGE_TYPE_CD']);
        foreach (['AuthCode' => 'AUTH_CODE', 'TransId' => 'TRANS_ID', 'HostRefNum' => 'HOST_REF_NUM'] as $root => $alias) {
            if ($result[$root] === '' && $result[$alias] !== '') {
                $result[$root] = $result[$alias];
            }
        }

        if ($result['Response'] === '' && $result['ProcReturnCode'] === '') {
            throw new ApiException('invalid_response', 'CMI API returned an unexpected response: ' . substr(trim(strip_tags($body)), 0, 150));
        }

        return $result;
    }

    private static function clean(string $value): string
    {
        return trim(strip_tags($value));
    }
}
