<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Service;

use Address;
use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Model\CmiTransaction;
use Cmi\PrestaShop\Payment\AccountResolver;
use Cmi\PrestaShop\Payment\Amounts;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Payment\TransactionGuard;
use Cmi\PrestaShop\Protocol\CmiProtocol;
use Cmi\PrestaShop\Repository\TransactionRepository;
use Country;
use Currency;
use Customer;
use Link;
use Order;
use State;
use Validate;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Prepares a payment: which account and currency CMI charges, the amount, the transaction
 * record, and the signed form that sends the customer to CMI's payment page.
 */
class PaymentRequest
{
    public const ERROR_CURRENCY = 'currency';
    public const ERROR_RATE = 'rate';
    public const ERROR_NAME = 'name';
    public const ERROR_CONFIGURATION = 'configuration';

    /** @var Settings */
    private $settings;
    /** @var TransactionRepository */
    private $repository;

    public function __construct(Settings $settings, TransactionRepository $repository)
    {
        $this->settings = $settings;
        $this->repository = $repository;
    }

    /**
     * What CMI will charge for an amount in a currency.
     *
     * @return array{account: string, client_id: string, currency_iso: string, currency_num: string, amount_charged: float, order_currency: string, amount_order: float, rate: float}|string
     *                                                                                                                                                                              an ERROR_* code when it cannot be paid with CMI
     */
    public function charge(Currency $currency, float $total)
    {
        if (!$this->settings->isPaymentConfigured()) {
            return self::ERROR_CONFIGURATION;
        }
        $route = AccountResolver::resolve($this->settings->storeCurrencies(), (string) $currency->iso_code);
        if ($route === null) {
            return self::ERROR_CURRENCY;
        }
        $clientId = $this->settings->clientId($route['account']);
        if ($clientId === '') {
            return self::ERROR_CONFIGURATION;
        }
        $rate = 1.0;
        if ($route['convert']) {
            // Never send an unconverted amount: CMI would charge it as MAD.
            $mad = new Currency((int) Currency::getIdByIsoCode(AccountResolver::MAD));
            if (!Validate::isLoadedObject($mad) || $mad->deleted) {
                return self::ERROR_RATE;
            }
            $rate = Amounts::rateBetween((float) $currency->conversion_rate, (float) $mad->conversion_rate, false);
            if ($rate <= 0) {
                return self::ERROR_RATE;
            }
        }

        return [
            'account' => $route['account'],
            'client_id' => $clientId,
            'currency_iso' => $route['currency_iso'],
            'currency_num' => $route['currency'],
            'amount_charged' => Amounts::toCharged($total, $rate),
            'order_currency' => strtoupper((string) $currency->iso_code),
            'amount_order' => round($total, 6),
            'rate' => $rate,
        ];
    }

    /** CMI receives names in Latin letters only: one typed only in Arabic would arrive empty. */
    public function hasValidName(Address $address): bool
    {
        return CmiProtocol::hasLatinName($address->firstname . ' ' . $address->lastname);
    }

    /**
     * Record the payment of the orders PrestaShop just created for a cart (or a new attempt to
     * pay them). The OrderId sent to CMI is the shop's prefix + the order reference.
     *
     * @param Order[] $orders
     */
    public function recordAttempt(array $orders, array $charge, ?CmiTransaction $existing = null): CmiTransaction
    {
        $first = $orders[0];
        $transaction = $existing ?? new CmiTransaction();
        if (!$existing) {
            $transaction->id_shop = (int) $first->id_shop;
            $transaction->id_cart = (int) $first->id_cart;
            $transaction->id_order = (int) $first->id;
            $transaction->order_reference = (string) $first->reference;
            $transaction->oid = TransactionGuard::applyOidPrefix($this->settings->oidPrefix(), (string) $first->reference);
            // First time sent: CMI transactions older than this are not this order's.
            $transaction->sent_at = time();
        }
        foreach ($charge as $field => $value) {
            $transaction->{$field} = $value;
        }
        $transaction->payment_state = PaymentState::INITIATED;
        $transaction->pending_checks = 0;
        // The order may stay awaiting payment (callback lost, customer declined and left):
        // the Complete Kit checks CMI automatically. Every new attempt restarts the checks.
        $transaction->next_check_at = $this->settings->isApiEnabled() ? time() + (int) PaymentState::pendingCheckDelay(0) : 0;
        $this->repository->save($transaction);

        return $transaction;
    }

    /**
     * The fields of the form posted to CMI's payment page, signed.
     *
     * @return array<string, string>
     */
    public function formFields(CmiTransaction $transaction, Customer $customer, Address $address, string $languageIso, Link $link): array
    {
        $order = new Order((int) $transaction->id_order);
        $country = new Country((int) $address->id_country);
        $state = $address->id_state ? new State((int) $address->id_state) : null;
        $serverCallback = $this->settings->callbackMode() === Settings::CALLBACK_SERVER;
        $returnUrl = $link->getModuleLink('cmi', 'return', [], true);

        $fields = [
            'clientid' => (string) $transaction->client_id,
            // Always 3-D Secure, as CMI requires (and as the WooCommerce plugin sends).
            'storetype' => '3d_pay_hosting',
            'hashAlgorithm' => 'ver3',
            'TranType' => 'PreAuth',
            'rnd' => (string) microtime(),
            'lang' => $this->settings->paymentLanguage($languageIso),
            'currency' => (string) $transaction->currency_num,
            'amount' => CmiProtocol::formatAmount((float) $transaction->amount_charged),
            'oid' => (string) $transaction->oid,
            'okUrl' => $returnUrl,
            'failUrl' => $returnUrl,
            'shopurl' => $link->getModuleLink('cmi', 'cancel', ['id_order' => (int) $order->id, 'key' => (string) $order->secure_key], true),
            'CallbackResponse' => $serverCallback ? 'true' : 'false',
            'AutoRedirect' => $this->settings->isAutoRedirect() ? 'true' : 'false',
            'refreshtime' => '5',
            'email' => (string) $customer->email,
            'BillToName' => CmiProtocol::withoutAccents($address->firstname . ' ' . $address->lastname),
            'BillToCompany' => CmiProtocol::withoutAccents((string) $address->company),
            'BillToStreet1' => CmiProtocol::withoutAccents(trim($address->address1 . ' ' . $address->address2)),
            'BillToCity' => CmiProtocol::withoutAccents((string) $address->city),
            'BillToStateProv' => $state && Validate::isLoadedObject($state) ? CmiProtocol::withoutAccents((string) $state->name) : '',
            'BillToCountry' => (string) $country->iso_code,
            'BillToPostalCode' => CmiProtocol::withoutAccents((string) $address->postcode),
            'tel' => CmiProtocol::cleanPhone((string) ($address->phone_mobile ?: $address->phone)),
            'encoding' => 'UTF-8',
        ];
        if ($serverCallback) {
            // CMI calls this after each payment. The answer decides the capture: ACTION=POSTAUTH
            // in automatic confirmation, APPROVED in manual confirmation (stays pre-authorized).
            $fields['callbackUrl'] = $link->getModuleLink('cmi', 'callback', [], true);
        }
        if ($transaction->isConverted()) {
            // Shown on CMI's page next to the MAD amount.
            $fields['amountCur'] = CmiProtocol::formatAmount((float) $transaction->amount_order);
            $fields['symbolCur'] = (string) $transaction->order_currency;
        }
        $fields['hash'] = CmiProtocol::requestHash($fields, $this->settings->storeKey());

        return $fields;
    }
}
