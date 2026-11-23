<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop;

use Cmi\PrestaShop\Api\ApiClient;
use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Repository\TransactionRepository;
use Cmi\PrestaShop\Service\CallbackHandler;
use Cmi\PrestaShop\Service\LegacyTransactions;
use Cmi\PrestaShop\Service\OrderUpdater;
use Cmi\PrestaShop\Service\PaymentRequest;
use Cmi\PrestaShop\Service\PendingChecker;
use Cmi\PrestaShop\Service\Texts;
use Cmi\PrestaShop\Service\TransactionManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds the module's services once per request, wired together.
 */
final class Services
{
    /** @var \PaymentModule */
    private $module;
    /** @var array<string, object> */
    private $instances = [];

    public function __construct(\PaymentModule $module)
    {
        $this->module = $module;
    }

    public function settings(): Settings
    {
        return $this->instances['settings'] ?? ($this->instances['settings'] = new Settings());
    }

    public function repository(): TransactionRepository
    {
        return $this->instances['repository'] ?? ($this->instances['repository'] = new TransactionRepository());
    }

    public function orders(): OrderUpdater
    {
        return $this->instances['orders'] ?? ($this->instances['orders'] = new OrderUpdater());
    }

    public function texts(): Texts
    {
        return $this->instances['texts'] ?? ($this->instances['texts'] = new Texts($this->module));
    }

    public function api(): ApiClient
    {
        return $this->instances['api'] ?? ($this->instances['api'] = new ApiClient($this->settings()));
    }

    public function manager(): TransactionManager
    {
        return $this->instances['manager'] ?? ($this->instances['manager'] = new TransactionManager(
            $this->settings(),
            $this->repository(),
            $this->orders(),
            $this->api(),
            $this->texts()
        ));
    }

    public function legacy(): LegacyTransactions
    {
        return $this->instances['legacy'] ?? ($this->instances['legacy'] = new LegacyTransactions($this->settings(), $this->repository()));
    }

    public function callbacks(): CallbackHandler
    {
        return $this->instances['callbacks'] ?? ($this->instances['callbacks'] = new CallbackHandler(
            $this->module,
            $this->settings(),
            $this->repository(),
            $this->orders(),
            $this->manager(),
            $this->legacy(),
            $this->texts()
        ));
    }

    public function paymentRequest(): PaymentRequest
    {
        return $this->instances['request'] ?? ($this->instances['request'] = new PaymentRequest($this->settings(), $this->repository()));
    }

    public function pendingChecker(): PendingChecker
    {
        return $this->instances['checker'] ?? ($this->instances['checker'] = new PendingChecker(
            $this->settings(),
            $this->repository(),
            $this->manager(),
            $this->orders(),
            $this->texts()
        ));
    }
}
