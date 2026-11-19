<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Service;

use Cmi\PrestaShop\Model\CmiTransaction;
use Order;
use OrderPayment;
use Validate;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Applies a CMI payment's outcome to the PrestaShop orders of its cart.
 */
class OrderUpdater
{
    /** @return Order[] every order of the transaction's cart (several with split shipments) */
    public function ordersOf(CmiTransaction $transaction): array
    {
        $orders = [];
        if ((string) $transaction->order_reference !== '') {
            foreach (Order::getByReference($transaction->order_reference) as $order) {
                if ((int) $order->id_cart === (int) $transaction->id_cart) {
                    $orders[(int) $order->id] = $order;
                }
            }
        }
        if (!$orders && (int) $transaction->id_order) {
            $order = new Order((int) $transaction->id_order);
            if (Validate::isLoadedObject($order)) {
                $orders[(int) $order->id] = $order;
            }
        }
        ksort($orders);

        return array_values($orders);
    }

    /** Move every order to a state; orders already in it are left alone. */
    public function setState(CmiTransaction $transaction, int $idOrderState): bool
    {
        if ($idOrderState <= 0) {
            return false;
        }
        $changed = false;
        foreach ($this->ordersOf($transaction) as $order) {
            if ((int) $order->getCurrentState() !== $idOrderState) {
                $order->setCurrentState($idOrderState);
                $changed = true;
            }
        }

        return $changed;
    }

    /** Move only the orders currently in one of the given states. */
    public function setStateIfIn(CmiTransaction $transaction, int $idOrderState, array $fromStates): bool
    {
        $fromStates = array_map('intval', $fromStates);
        $changed = false;
        foreach ($this->ordersOf($transaction) as $order) {
            $current = (int) $order->getCurrentState();
            if ($current !== $idOrderState && in_array($current, $fromStates, true)) {
                $order->setCurrentState($idOrderState);
                $changed = true;
            }
        }

        return $changed;
    }

    public function isPaid(CmiTransaction $transaction): bool
    {
        foreach ($this->ordersOf($transaction) as $order) {
            if ($order->hasBeenPaid()) {
                return true;
            }
        }

        return false;
    }

    /** @return int[] current state of each order */
    public function currentStates(CmiTransaction $transaction): array
    {
        return array_map(static function (Order $order): int {
            return (int) $order->getCurrentState();
        }, $this->ordersOf($transaction));
    }

    /**
     * Write CMI's transaction number and the masked card on the order payments that PrestaShop
     * created when the orders became paid.
     */
    public function recordPaymentDetails(CmiTransaction $transaction, array $answer): void
    {
        $transId = substr(trim((string) ($answer['TransId'] ?? '')), 0, 254);
        if ($transId === '' || (string) $transaction->order_reference === '') {
            return;
        }
        $pan = preg_replace('/[^0-9*Xx ]/', '', (string) ($answer['MaskedPan'] ?? ''));
        $lastDigits = substr((string) preg_replace('/\D/', '', (string) $pan), -4);
        $brand = substr(trim((string) preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($answer['EXTRA_CARDBRAND'] ?? ''))), 0, 254);
        foreach (OrderPayment::getByOrderReference($transaction->order_reference) as $payment) {
            if ((string) $payment->transaction_id !== '' && (string) $payment->transaction_id !== $transId) {
                continue;
            }
            $payment->transaction_id = $transId;
            if ($lastDigits !== '') {
                $payment->card_number = '**** ' . $lastDigits;
            }
            if ($brand !== '') {
                $payment->card_brand = $brand;
            }
            $payment->update();
        }
    }
}
