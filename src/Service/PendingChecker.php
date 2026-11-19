<?php
/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Service;

use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Entity\CmiTransaction;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Payment\TransactionGuard;
use Cmi\PrestaShop\Protocol\ApiException;
use Cmi\PrestaShop\Repository\TransactionRepository as Repo;
use Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Automatic status checks (Complete Kit) of orders left awaiting payment: the CMI callback
 * failed or never came, or the customer was declined and left (CMI then sends nothing).
 *
 * Read-only towards CMI: only ever sends status queries, never confirms, voids or refunds.
 * Checks run about 15 minutes, 1 h 15 and 7 h 15 after the customer was sent to CMI.
 */
class PendingChecker
{
    /** @var Settings */
    private $settings;
    /** @var Repo */
    private $repository;
    /** @var TransactionManager */
    private $manager;
    /** @var OrderUpdater */
    private $orders;
    /** @var Texts */
    private $texts;

    public function __construct(Settings $settings, Repo $repository, TransactionManager $manager, OrderUpdater $orders, Texts $texts)
    {
        $this->settings = $settings;
        $this->repository = $repository;
        $this->manager = $manager;
        $this->orders = $orders;
        $this->texts = $texts;
    }

    /**
     * Run the checks that are due for one shop (each shop of a multistore has its own CMI settings).
     *
     * @return int number of orders checked
     */
    public function runDue(int $idShop, int $limit = 20): int
    {
        $checked = 0;
        foreach ($this->repository->findDueChecks(time(), $limit, $idShop) as $transaction) {
            if ($this->check($transaction)) {
                ++$checked;
            }
        }

        return $checked;
    }

    public function check(CmiTransaction $transaction): bool
    {
        if (!$this->settings->isApiEnabled()) {
            $transaction->next_check_at = 0;
            $this->repository->save($transaction);

            return false;
        }
        if (!$this->repository->acquireLock($transaction)) {
            return false;
        }
        try {
            $transaction = $this->repository->refresh($transaction);
            $awaitingState = $this->settings->awaitingStateId();
            if (!PaymentState::isAwaitingPayment((string) $transaction->payment_state)
                || !in_array($awaitingState, $this->orders->currentStates($transaction), true)) {
                $transaction->next_check_at = 0;
                $this->repository->save($transaction);

                return false;
            }

            $checksDone = (int) $transaction->pending_checks + 1;
            $lastCheck = PaymentState::pendingCheckDelay($checksDone) === null;
            try {
                $status = $this->manager->queryStatus($transaction);
                $notFound = TransactionGuard::isNotFound($status);
                if (!$notFound && PaymentState::pendingCheckApplies((string) $status['TRANS_STAT'], $lastCheck, (string) $status['ProcReturnCode'])) {
                    $result = $this->manager->sync($transaction, $status);
                    // Another website's payment will not become this order's: stop checking.
                    if ($result['status'] === 'FOREIGN' || !in_array($awaitingState, $this->orders->currentStates($transaction), true)) {
                        $transaction->next_check_at = 0;
                        $this->repository->save($transaction);

                        return true;
                    }
                } elseif ($lastCheck && $notFound) {
                    // Still no payment hours later: the customer left. Cancel the order so its
                    // products return to stock (PrestaShop has no automatic expiry of unpaid orders).
                    $transaction->payment_state = PaymentState::FAILED;
                    $this->orders->setStateIfIn($transaction, (int) Configuration::get('PS_OS_CANCELED'), [$awaitingState]);
                    $this->repository->log($transaction, Repo::LOG_INFO, $this->texts->admin('Automatic check: still no payment on CMI hours after the customer was sent there. The order was cancelled.'));
                }
            } catch (ApiException $e) {
                $this->repository->log($transaction, Repo::LOG_WARNING, $this->texts->admin('Automatic check: CMI could not be reached (%error%).', ['%error%' => $e->getMessage()]));
            }

            $delay = PaymentState::pendingCheckDelay($checksDone);
            $transaction->pending_checks = $checksDone;
            $transaction->next_check_at = $delay !== null ? time() + $delay : 0;
            $this->repository->save($transaction);

            return true;
        } finally {
            $this->repository->releaseLock($transaction);
        }
    }
}
