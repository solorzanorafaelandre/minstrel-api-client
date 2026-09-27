<?php

declare(strict_types=1);

namespace Minstrel\ApiClient;

/**
 * One page of a list (docs/12 §1): `data`, and the cursors to the next and
 * previous pages — pass them back unchanged. `Transport::all()` walks them.
 */
final readonly class Page
{
    /** @param  list<array<string, mixed>>  $data */
    public function __construct(
        public array $data,
        public ?string $nextCursor,
        public ?string $prevCursor,
        public ?string $requestId,
    ) {}
}
