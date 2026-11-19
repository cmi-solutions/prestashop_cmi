<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Repository;

use Cmi\PrestaShop\Model\CmiTransaction;
use Db;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reads and writes CMI transactions and their history. Every value reaching SQL is cast or
 * escaped here.
 */
class TransactionRepository
{
    public const LOG_INFO = 'info';
    public const LOG_WARNING = 'warning';
    public const LOG_ERROR = 'error';

    /** How long an operation may hold a transaction before the lock is considered stale. */
    private const LOCK_SECONDS = 120;

    public static function table(): string
    {
        return _DB_PREFIX_ . CmiTransaction::$definition['table'];
    }

    public static function logTable(): string
    {
        return _DB_PREFIX_ . 'cmi_transaction_log';
    }

    public function findByOid(string $oid, int $idShop): ?CmiTransaction
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_cmi_transaction` FROM `' . self::table() . '`'
            . ' WHERE `oid` = \'' . pSQL($oid) . '\' AND `id_shop` = ' . (int) $idShop
            . ' ORDER BY `id_cmi_transaction` DESC'
        );

        return $this->load($id);
    }

    public function findByCart(int $idCart): ?CmiTransaction
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_cmi_transaction` FROM `' . self::table() . '` WHERE `id_cart` = ' . (int) $idCart
            . ' ORDER BY `id_cmi_transaction` DESC'
        );

        return $this->load($id);
    }

    public function findByOrder(\Order $order): ?CmiTransaction
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_cmi_transaction` FROM `' . self::table() . '`'
            . ' WHERE `id_cart` = ' . (int) $order->id_cart . ' AND `id_shop` = ' . (int) $order->id_shop
            . ' ORDER BY `id_cmi_transaction` DESC'
        );

        return $this->load($id);
    }

    /**
     * Transactions whose automatic status check is due.
     *
     * @return CmiTransaction[]
     */
    public function findDueChecks(int $now, int $limit, int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_cmi_transaction` FROM `' . self::table() . '`'
            . ' WHERE `next_check_at` > 0 AND `next_check_at` <= ' . (int) $now . ' AND `id_shop` = ' . (int) $idShop
            . ' ORDER BY `next_check_at` ASC LIMIT ' . max(1, (int) $limit)
        );
        $transactions = [];
        foreach ($rows ?: [] as $row) {
            $transaction = $this->load((int) $row['id_cmi_transaction']);
            if ($transaction) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }

    /**
     * Hold a transaction for one operation. Atomic: only one request can hold it at a time,
     * so a callback, a browser return and a merchant action never act on it together.
     */
    public function acquireLock(CmiTransaction $transaction): bool
    {
        $now = time();
        $done = Db::getInstance()->execute(
            'UPDATE `' . self::table() . '` SET `lock_until` = ' . ($now + self::LOCK_SECONDS)
            . ' WHERE `id_cmi_transaction` = ' . (int) $transaction->id
            . ' AND `lock_until` < ' . $now
        );
        if (!$done || Db::getInstance()->Affected_Rows() !== 1) {
            return false;
        }
        $transaction->lock_until = $now + self::LOCK_SECONDS;

        return true;
    }

    /** Wait (up to $seconds) for a transaction held by another request. */
    public function acquireLockWaiting(CmiTransaction $transaction, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($this->acquireLock($transaction)) {
                return true;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function releaseLock(CmiTransaction $transaction): void
    {
        Db::getInstance()->execute(
            'UPDATE `' . self::table() . '` SET `lock_until` = 0 WHERE `id_cmi_transaction` = ' . (int) $transaction->id
        );
        $transaction->lock_until = 0;
    }

    /** Reload a transaction's values written by another request. */
    public function refresh(CmiTransaction $transaction): CmiTransaction
    {
        return $this->load((int) $transaction->id) ?? $transaction;
    }

    public function save(CmiTransaction $transaction): bool
    {
        return (bool) $transaction->save();
    }

    public function log(CmiTransaction $transaction, string $level, string $message): void
    {
        Db::getInstance()->insert('cmi_transaction_log', [
            'id_cmi_transaction' => (int) $transaction->id,
            'level' => pSQL($level),
            'message' => pSQL(substr($message, 0, 2000)),
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int, array{level: string, message: string, date_add: string}> */
    public function logs(CmiTransaction $transaction, int $limit = 50): array
    {
        return Db::getInstance()->executeS(
            'SELECT `level`, `message`, `date_add` FROM `' . self::logTable() . '`'
            . ' WHERE `id_cmi_transaction` = ' . (int) $transaction->id
            . ' ORDER BY `id_cmi_transaction_log` DESC LIMIT ' . max(1, (int) $limit)
        ) ?: [];
    }

    private function load(int $id): ?CmiTransaction
    {
        if ($id <= 0) {
            return null;
        }
        $transaction = new CmiTransaction($id);

        return \Validate::isLoadedObject($transaction) ? $transaction : null;
    }
}
