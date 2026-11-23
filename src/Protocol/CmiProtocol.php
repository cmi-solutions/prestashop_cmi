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
 * CMI message formats: request and callback signatures, XML API requests, cleaned values.
 *
 * No PrestaShop call in here, so the unit tests run this exact code.
 */
final class CmiProtocol
{
    public const OID_MAX_LENGTH = 64;

    /**
     * Signature of the payment form sent to CMI (hashAlgorithm "ver3"), as in CMI's PHP example:
     * fields in natural case-insensitive order, values HTML-decoded and trimmed, "hash" and
     * "encoding" left out, the store key appended, SHA-512, base64.
     *
     * @param array<string, scalar> $fields
     */
    public static function requestHash(array $fields, string $storeKey): string
    {
        // Explicit flags: PHP 8.1 changed html_entity_decode()'s defaults, and the signature
        // must be the same on every PHP version (these are the PHP 8.1+ defaults).
        $flags = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401;

        return self::hash($fields, $storeKey, static function ($value) use ($flags): string {
            return trim(html_entity_decode(html_entity_decode((string) $value, $flags, 'UTF-8'), $flags, 'UTF-8'));
        });
    }

    /**
     * Whether the amount CMI approved is the amount sent. A payment started with version 1.x
     * may have been converted with slightly different rounding: 1% tolerance for those.
     */
    public static function amountMatches(float $approved, float $sent, bool $legacy = false): bool
    {
        if ($sent <= 0) {
            return false;
        }
        $tolerance = $legacy ? max(0.01, round($sent * 0.01, 2)) : 0.009;

        return abs(round($approved, 2) - round($sent, 2)) <= $tolerance;
    }

    /**
     * Signature CMI puts on a server callback or a browser return, as in CMI's callback example:
     * one trailing line break removed and HTML entities decoded, nothing trimmed.
     *
     * @param array<string, scalar> $fields every POST field received
     */
    public static function callbackHash(array $fields, string $storeKey): string
    {
        return self::hash($fields, $storeKey, static function ($value): string {
            return html_entity_decode((string) preg_replace("/\n$/", '', (string) $value), ENT_QUOTES, 'UTF-8');
        });
    }

    /** Whether a received signature matches, compared in constant time. */
    public static function isValidCallback(array $fields, string $storeKey): bool
    {
        $received = '';
        foreach ($fields as $name => $value) {
            if (strtolower((string) $name) === 'hash') {
                $received = (string) $value;
            }
        }
        if ($received === '' || $storeKey === '') {
            return false;
        }

        return hash_equals(self::callbackHash($fields, $storeKey), $received);
    }

    private static function hash(array $fields, string $storeKey, callable $prepare): string
    {
        $names = array_map('strval', array_keys($fields));
        natcasesort($names);
        $plain = '';
        foreach ($names as $name) {
            if (in_array(strtolower($name), ['hash', 'encoding'], true)) {
                continue;
            }
            $plain .= self::escape($prepare($fields[$name])) . '|';
        }
        $plain .= self::escape($storeKey);

        return base64_encode((string) pack('H*', hash('sha512', $plain)));
    }

    private static function escape(string $value): string
    {
        return str_replace('|', '\\|', str_replace('\\', '\\\\', $value));
    }

    /**
     * Remove accents and keep only [a-zA-Z0-9_ -], as CMI's PHP example does for names and
     * addresses. Text without Latin letters (for example Arabic) comes out empty.
     */
    public static function withoutAccents(string $text): string
    {
        $text = htmlentities($text, ENT_NOQUOTES, 'UTF-8');
        $text = (string) preg_replace('#&([A-Za-z])(?:acute|cedil|caron|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $text);
        $text = (string) preg_replace('#&([A-Za-z]{2})(?:lig);#', '\1', $text);
        $text = (string) preg_replace('#&[^;]+;#', '', $text);
        $text = (string) preg_replace('/[^a-zA-Z0-9_ -]/', '', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** Phone number for CMI: digits, spaces and a leading "+" kept. */
    public static function cleanPhone(string $phone): string
    {
        $phone = trim($phone);
        $plus = strpos($phone, '+') === 0 ? '+' : '';

        return $plus . trim((string) preg_replace('/[^0-9 ]/', '', $phone));
    }

    /** Whether a customer name still has Latin letters once cleaned for CMI. */
    public static function hasLatinName(string $name): bool
    {
        return trim(self::withoutAccents($name), ' -_') !== '';
    }

    /** An order identifier CMI accepts: letters, digits, "-" and "_", 64 characters at most. */
    public static function sanitizeOid(string $rawOid): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '', self::withoutAccents($rawOid)), 0, self::OID_MAX_LENGTH);
    }

    /**
     * XML request for CMI's API, every value escaped.
     *
     * @param string $type PostAuth, Void, Credit, ORDERSTATUS or ORDERHISTORY
     * @param string|null $total amount ("150.00"): partial PostAuth, Credit (required)
     * @param string|null $currency ISO 4217 numeric code, for Credit ("504")
     *
     * @return string|null the XML, or null for an unknown type
     */
    public static function xmlRequest(string $type, string $username, string $password, string $clientId, string $orderId, ?string $total = null, ?string $currency = null): ?string
    {
        $e = static function (string $value): string {
            return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };
        $head = '<?xml version="1.0" encoding="UTF-8"?><CC5Request>'
            . '<Name>' . $e($username) . '</Name>'
            . '<Password>' . $e($password) . '</Password>'
            . '<ClientId>' . $e($clientId) . '</ClientId>';
        $oid = '<OrderId>' . $e($orderId) . '</OrderId>';

        switch ($type) {
            case 'Void':
                return $head . '<Type>Void</Type>' . $oid . '</CC5Request>';
            case 'PostAuth':
                return $head . '<Type>PostAuth</Type>' . $oid
                    . ($total !== null ? '<Total>' . $e($total) . '</Total>' : '')
                    . '</CC5Request>';
            case 'Credit':
                return $head . '<Type>Credit</Type>'
                    . '<Total>' . $e((string) $total) . '</Total>'
                    . '<Currency>' . $e((string) $currency) . '</Currency>'
                    . $oid . '</CC5Request>';
            case 'ORDERSTATUS':
            case 'ORDERHISTORY':
                return $head . $oid . '<Extra><' . $type . '>QUERY</' . $type . '></Extra></CC5Request>';
        }

        return null;
    }

    /** Amount as CMI expects it: two decimals, dot separator, no thousands separator. */
    public static function formatAmount(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }
}
