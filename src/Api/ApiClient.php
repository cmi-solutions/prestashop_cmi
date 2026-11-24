<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Api;

use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Model\CmiTransaction;
use Cmi\PrestaShop\Protocol\ApiException;
use Cmi\PrestaShop\Protocol\ApiResponseParser;
use Cmi\PrestaShop\Protocol\CmiProtocol;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Sends XML requests to CMI's API (Complete Kit) for one transaction.
 */
class ApiClient
{
    private const TIMEOUT_SECONDS = 45;

    /** @var Settings */
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param string $type PostAuth, Void, Credit, ORDERSTATUS or ORDERHISTORY
     * @param string|null $total amount in the charged currency (partial PostAuth, Credit)
     *
     * @return array<string, mixed> parsed answer
     *
     * @throws ApiException
     */
    public function request(string $type, CmiTransaction $transaction, ?string $total = null): array
    {
        if (!$this->settings->isApiEnabled()) {
            throw new ApiException('not_configured', 'The CMI API is not configured (Complete Kit with API URL, username and password).');
        }
        $clientId = (string) $transaction->client_id !== '' ? (string) $transaction->client_id : $this->settings->clientId((string) $transaction->account);
        $xml = CmiProtocol::xmlRequest(
            $type,
            $this->settings->apiUsername(),
            $this->settings->apiPassword(),
            $clientId,
            (string) $transaction->oid,
            $total,
            (string) $transaction->currency_num
        );
        if ($xml === null) {
            throw new ApiException('unknown_type', 'Unknown CMI API request type.');
        }
        $url = $this->settings->apiUrl();
        if (!preg_match('#^https://#i', $url)) {
            throw new ApiException('insecure_url', 'The CMI API URL must start with https://.');
        }

        \PrestaShopLogger::addLog(sprintf('CMI API %s request for OrderId %s', $type, $transaction->oid), 1, null, 'Cart', (int) $transaction->id_cart, true);
        $body = $this->post($url, $xml);

        return ApiResponseParser::parse($body);
    }

    /** @throws ApiException */
    protected function post(string $url, string $xml): string
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('no_curl', 'The PHP cURL extension is required to call the CMI API.');
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=UTF-8', 'Accept: text/xml, application/xml'],
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            // Does nothing since PHP 8.0, and is deprecated in PHP 8.5.
            curl_close($curl);
        }

        if ($body === false) {
            \PrestaShopLogger::addLog('CMI API connection failed: ' . $error, 3, null, null, null, true);
            throw new ApiException('connection_failed', 'Could not reach the CMI API: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException('http_error', sprintf('The CMI API answered with HTTP error %d.', $status));
        }

        return (string) $body;
    }
}
