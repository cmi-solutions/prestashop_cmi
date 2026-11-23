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

/** A CMI API call that could not be made or answered something unreadable. */
class ApiException extends \RuntimeException
{
    /** @var string */
    private $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    /** Short machine-readable reason, e.g. "not_configured", "http_error", "html_response". */
    public function getReason(): string
    {
        return $this->reason;
    }
}
