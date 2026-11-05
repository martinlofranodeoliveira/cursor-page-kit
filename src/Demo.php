<?php
declare(strict_types=1);

namespace CursorPageKit;

use PDO;
use PDOException;

final class Demo
{
    /** @return array{pages: list<array>, total_items: int, unique_items: int} */
    public static function run(): array
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new PageKitException('PDO_SQLite is required.');
        }
        $path = @tempnam(sys_get_temp_dir(), 'cursor-page-kit-');
        if ($path === false) {
            throw new PageKitException('Could not allocate a temporary demo database.');
        }
        $pdo = null;
        $insert = null;
        $pager = null;
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE,
            ]);
            $pdo->exec('PRAGMA journal_mode = DELETE');
            $pdo->exec('CREATE TABLE items(id INTEGER PRIMARY KEY, sort_key INTEGER NOT NULL, title TEXT NOT NULL)');
            $pdo->exec('CREATE INDEX items_order ON items(sort_key, id)');
            $rows = [
                [1, 0, 'Synthetic alpha'], [2, -4, 'Synthetic beta'],
                [3, 0, 'Synthetic gamma'], [4, -4, 'Synthetic delta'],
                [5, 7, 'Synthetic epsilon'], [6, 0, 'Synthetic zeta'],
                [7, 7, 'Synthetic eta'], [8, PHP_INT_MAX, 'Synthetic theta'],
                [9, PHP_INT_MIN, 'Synthetic iota'],
            ];
            $insert = $pdo->prepare('INSERT INTO items(id, sort_key, title) VALUES(:id, :sort_key, :title)');
            foreach ($rows as [$id, $sortKey, $title]) {
                $insert->bindValue(':id', $id, PDO::PARAM_INT);
                $insert->bindValue(':sort_key', $sortKey, PDO::PARAM_INT);
                $insert->bindValue(':title', $title, PDO::PARAM_STR);
                $insert->execute();
            }
            $insert = null;
            $pdo->beginTransaction();
            $pager = new Pager($pdo);
            $pages = [];
            $seen = [];
            $ids = [];
            $cursor = null;
            for ($iteration = 0; $iteration < 9; $iteration++) {
                $page = $pager->page(3, $cursor);
                $pages[] = $page;
                foreach ($page['items'] as $item) {
                    if (isset($seen[$item['id']])) {
                        throw new PageKitException('Demo traversal verification failed.');
                    }
                    $seen[$item['id']] = true;
                    $ids[] = $item['id'];
                }
                if (!$page['has_more']) {
                    break;
                }
                $cursor = $page['next_cursor'];
            }
            if ($ids !== [9, 2, 4, 1, 3, 6, 5, 7, 8] || $page['next_cursor'] !== null
                || $page['has_more'] || count($pages) !== 3) {
                throw new PageKitException('Demo traversal verification failed.');
            }
            $pdo->commit();
            return ['pages' => $pages, 'total_items' => count($ids), 'unique_items' => count($seen)];
        } catch (PDOException $exception) {
            throw new PageKitException('Could not run the temporary SQLite demo.', 0, $exception);
        } finally {
            $insert = null;
            $pager = null;
            if ($pdo !== null && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo = null;
            foreach ([$path . '-journal', $path . '-wal', $path . '-shm', $path] as $ownedPath) {
                if (file_exists($ownedPath) && !@unlink($ownedPath)) {
                    throw new PageKitException('Could not remove a temporary demo file.');
                }
            }
        }
    }
}
