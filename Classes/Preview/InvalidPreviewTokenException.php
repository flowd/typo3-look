<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Preview;

/**
 * A preview descriptor or token that does not verify: wrong signature, malformed payload, or,
 * for tokens, expired. "expired" is the only case the browser may retry with a fresh token.
 */
final class InvalidPreviewTokenException extends \RuntimeException
{
    public function __construct(string $message, int $code, public readonly bool $expired = false)
    {
        parent::__construct($message, $code);
    }
}
