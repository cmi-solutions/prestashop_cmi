<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Service;

use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Model\CmiTransaction;
use Cmi\PrestaShop\Payment\AccountResolver;
use Cmi\PrestaShop\Payment\Amounts;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Repository\TransactionRepository;
use Currency;
use Order;
use OrderPayment;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Orders paid with version 1.x of the module, which had no transaction record. Version 1.x
 * sent the cart ID as the CMI OrderId and created the order only once CMI approved it.
 */
class LegacyTransactions
{
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
     * Transaction record for an order paid with version 1.x, so Check CMI and the actions can be
     * used on it. Its CMI state is unknown until checked: version 1.x marked orders paid even
     * when CMI had only pre-authorized them.
     */
    public function forOrder(Order $order, string $moduleName): ?CmiTransaction
    {
        if ($order->module !== $moduleName) {
            return null;
        }
        $existing = $this->repository->findByOrder($order);
        if ($existing) {
            return $existing;
        }
        $currency = new Currency((int) $order->id_currency);
        $route = AccountResolver::resolve($this->settings->storeCurrencies(), (string) $currency->iso_code);
        $account = $route['account'] ?? AccountResolver::INSHORE;
        $total = 0.0;
        foreach (Order::getByReference($order->reference) as $sibling) {
            if ((int) $sibling->id_cart === (int) $order->id_cart) {
                $total += (float) $sibling->total_paid_tax_incl;
            }
        }
        // The charged amount is only certain when CMI charged the order's own currency.
        $sameCurrency = $route !== null && !$route['convert'];

        $transaction = new CmiTransaction();
        $transaction->id_shop = (int) $order->id_shop;
        $transaction->id_cart = (int) $order->id_cart;
        $transaction->id_order = (int) $order->id;
        $transaction->order_reference = (string) $order->reference;
        $transaction->oid = (string) (int) $order->id_cart;
        $transaction->payment_state = '';
        $transaction->legacy = true;
        $transaction->account = $account;
        $transaction->client_id = $this->settings->clientId($account);
        $transaction->currency_iso = $route['currency_iso'] ?? AccountResolver::MAD;
        $transaction->currency_num = $route['currency'] ?? AccountResolver::MAD_NUMERIC;
        $transaction->order_currency = strtoupper((string) $currency->iso_code);
        $transaction->amount_order = $total;
        $transaction->amount_charged = $sameCurrency ? round($total, 2) : 0.0;
        $transaction->rate = $sameCurrency ? 1.0 : 0.0;
        $transaction->sent_at = 0;
        // Version 1.x saved CMI's TransId on the order payment: it proves ownership.
        $ids = [];
        foreach (OrderPayment::getByOrderReference($order->reference) as $payment) {
            if ((string) $payment->transaction_id !== '') {
                $ids[] = (string) $payment->transaction_id;
            }
        }
        $transaction->own_ids = (string) json_encode(array_values(array_unique($ids)));
        $this->repository->save($transaction);

        return $transaction;
    }

    /**
     * A payment started with version 1.x just before the upgrade (OrderId = cart ID) and
     * answered by CMI afterwards: record it so the callback can be processed.
     */
    public function forInFlightCart(int $idCart, array $callback): ?CmiTransaction
    {
        $cart = new \Cart($idCart);
        if (!\Validate::isLoadedObject($cart)) {
            return null;
        }
        $currency = new Currency((int) $cart->id_currency);
        $route = AccountResolver::resolve($this->settings->storeCurrencies(), (string) $currency->iso_code);
        if ($route === null) {
            return null;
        }
        $orderTotal = (float) $cart->getOrderTotal(true, \Cart::BOTH);
        // The amount CMI should have approved, computed from the cart (never taken from the
        // callback): the callback handler compares the two before creating the order.
        $rate = 1.0;
        if ($route['convert']) {
            $mad = new Currency((int) Currency::getIdByIsoCode(AccountResolver::MAD));
            $rate = \Validate::isLoadedObject($mad) && !$mad->deleted
                ? Amounts::rateBetween((float) $currency->conversion_rate, (float) $mad->conversion_rate, false)
                : 0.0;
            if ($rate <= 0) {
                \PrestaShopLogger::addLog('CMI: payment of cart ' . (int) $cart->id . ' (version 1.x) cannot be checked: no exchange rate to MAD.', 3, null, 'Cart', (int) $cart->id, true);

                return null;
            }
        }
        $charged = Amounts::toCharged($orderTotal, $rate);

        $transaction = new CmiTransaction();
        $transaction->id_shop = (int) $cart->id_shop;
        $transaction->id_cart = (int) $cart->id;
        $transaction->oid = (string) (int) $cart->id;
        $transaction->payment_state = PaymentState::INITIATED;
        $transaction->legacy = true;
        $transaction->account = $route['account'] ?? AccountResolver::INSHORE;
        $transaction->client_id = (string) ($callback['clientid'] ?? $this->settings->clientId($transaction->account));
        $transaction->currency_iso = $route['currency_iso'] ?? AccountResolver::MAD;
        $transaction->currency_num = $route['currency'] ?? AccountResolver::MAD_NUMERIC;
        $transaction->order_currency = strtoupper((string) $currency->iso_code);
        $transaction->amount_order = $orderTotal;
        $transaction->amount_charged = $charged;
        $transaction->rate = $rate;
        $transaction->sent_at = time() - 3600;
        $this->repository->save($transaction);

        return $transaction;
    }
}
