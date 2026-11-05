<?php
declare(strict_types=1);

namespace CursorPageKit;

final readonly class CursorPosition
{
    public int $sortKey;
    public int $id;

    public function __construct(mixed $sortKey, mixed $id)
    {
        if (!is_int($sortKey) || !is_int($id) || $id < 1) {
            throw new PageKitException('Cursor values must be integers with a positive id.');
        }
        $this->sortKey = $sortKey;
        $this->id = $id;
    }
}
