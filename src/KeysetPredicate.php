<?php
declare(strict_types=1);

namespace CursorPageKit;

use PDO;
use PDOStatement;

/** Fixed, unqualified column names; no caller-controlled SQL or identifiers. */
final readonly class KeysetPredicate
{
    private function __construct(private ?CursorPosition $position)
    {
    }

    public static function after(?CursorPosition $position): self
    {
        return new self($position);
    }

    public function sql(): string
    {
        return $this->position === null
            ? '1 = 1'
            : '(sort_key, id) > (:cursor_sort_key, :cursor_id)';
    }

    public function bind(PDOStatement $statement): void
    {
        if ($this->position !== null) {
            $statement->bindValue(':cursor_sort_key', $this->position->sortKey, PDO::PARAM_INT);
            $statement->bindValue(':cursor_id', $this->position->id, PDO::PARAM_INT);
        }
    }
}
