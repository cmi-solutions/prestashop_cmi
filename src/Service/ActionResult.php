<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Outcome of a merchant action (Confirm, Void, Refund, Check CMI), shown on the order page. */
final class ActionResult
{
    /** @var bool */
    public $success;
    /** @var string */
    public $message;
    /** @var string short code, e.g. "foreign", "in_progress", "PRE_AUTHORIZED" */
    public $code;

    public function __construct(bool $success, string $message, string $code = '')
    {
        $this->success = $success;
        $this->message = $message;
        $this->code = $code;
    }

    public static function ok(string $message, string $code = ''): self
    {
        return new self(true, $message, $code);
    }

    public static function error(string $message, string $code = ''): self
    {
        return new self(false, $message, $code);
    }
}
