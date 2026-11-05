<?php
declare(strict_types=1);

namespace CursorPageKit;

use PDO;
use PDOException;

final class Pager
{
    public const MAX_PAGE_SIZE = 100;
    public const MAX_TITLE_BYTES = 1024;

    public function __construct(private readonly PDO $pdo)
    {
        SqliteDatabase::assertSchema($pdo);
    }

    /**
     * @return array{items: list<array{id: int, sort_key: int, title: string}>, has_more: bool, next_cursor: ?string}
     */
    public function page(mixed $pageSize = 20, mixed $cursor = null): array
    {
        if (!is_int($pageSize) || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new PageKitException('Page size must be an integer from 1 to 100.');
        }
        $position = $cursor === null ? null : Cursor::decode($cursor);
        $predicate = KeysetPredicate::after($position);
        $statement = null;
        try {
            // Only the library's fixed predicate is concatenated. Bound the PHP title payload
            // before fetching; reject rather than silently truncate invalid/oversized titles.
            $statement = $this->pdo->prepare(
                'SELECT id, sort_key, CASE WHEN length(CAST(title AS BLOB)) = 0 THEN \'\' '
                . 'ELSE substr(CAST(title AS BLOB), 1, 1025) END AS title, '
                . 'typeof(sort_key) AS sort_type, typeof(title) AS title_type '
                . 'FROM main.items WHERE ' . $predicate->sql()
                . ' ORDER BY sort_key ASC, id ASC LIMIT :row_limit'
            );
            $predicate->bind($statement);
            $statement->bindValue(':row_limit', $pageSize + 1, PDO::PARAM_INT);
            $statement->execute();
            $items = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (!is_int($row['id']) || $row['id'] < 1 || !is_int($row['sort_key'])
                    || $row['sort_type'] !== 'integer' || $row['title_type'] !== 'text'
                    || !is_string($row['title']) || strlen($row['title']) > self::MAX_TITLE_BYTES
                    || preg_match('//u', $row['title']) !== 1) {
                    throw new PageKitException('A selected row violates the data contract.');
                }
                $items[] = ['id' => $row['id'], 'sort_key' => $row['sort_key'], 'title' => $row['title']];
            }
        } catch (PDOException $exception) {
            throw new PageKitException('Could not read a page from SQLite.', 0, $exception);
        } finally {
            $statement?->closeCursor();
        }
        $hasMore = count($items) > $pageSize;
        if ($hasMore) {
            array_pop($items);
        }
        $last = $items === [] ? null : $items[array_key_last($items)];
        return [
            'items' => $items,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $last !== null ? Cursor::encode($last['sort_key'], $last['id']) : null,
        ];
    }
}
