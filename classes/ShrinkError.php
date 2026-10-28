<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

/**
 * Image shrinking refusal (ImageShrinker, ReindexService): a stable code the
 * admin translates (MAINT.SHRINK_ERR_<CODE> key) and an English sentence,
 * shown as is by the CLI.
 */
final class ShrinkError extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
