<?php

declare(strict_types=1);

namespace Minstrel\ApiClient;

use RuntimeException;

/**
 * An error answer from the API: `{error: {code, message, details[], request_id}}`
 * (docs/12 §1). `code` is stable and machine-readable; quote `requestId` to support.
 */
final class MinstrelApiException extends RuntimeException
{
    /** @param  list<array<string, mixed>>  $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $status);
    }
}
