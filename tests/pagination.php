<?php
declare(strict_types=1);

use CursorPageKit\Cursor;
use CursorPageKit\CursorPosition;
use CursorPageKit\KeysetPredicate;
use CursorPageKit\Pager;
use CursorPageKit\SqliteDatabase;

test('unqualified linked SQLite versions are rejected without changing caller rows', function (): void {
    foreach (['3.37.0', '3.40.1', '3.46.1', '3.51.2'] as $version) {
        $pdo = memoryItems([[1, 0, 'Synthetic runtime guard']]);
        $pdo->sqliteCreateFunction('sqlite_version', static fn (): string => $version, 0);
        throws(fn () => new Pager($pdo));
        same('Synthetic runtime guard', $pdo->query('SELECT title FROM items WHERE id=1')->fetchColumn());
        same('memory', $pdo->query('PRAGMA journal_mode')->fetchColumn());
    }
});

test('empty and single-item public pages', function (): void {
    same(['items' => [], 'has_more' => false, 'next_cursor' => null], (new Pager(memoryItems()))->page(2));
    same([
        'items' => [['id' => 12, 'sort_key' => -8, 'title' => 'Synthetic only']],
        'has_more' => false, 'next_cursor' => null,
    ], (new Pager(memoryItems([[12, -8, 'Synthetic only']])))->page(1));
});

test('exact boundary and one-row lookahead do not lose rows', function (): void {
    $rows = [[20, 0, 'Synthetic B'], [10, 0, 'Synthetic A']];
    $exact = (new Pager(memoryItems($rows)))->page(2);
    same([10, 20], array_column($exact['items'], 'id'));
    same(false, $exact['has_more']);
    same(null, $exact['next_cursor']);
    $pager = new Pager(memoryItems([...$rows, [30, 0, 'Synthetic C']]));
    $first = $pager->page(2);
    same([10, 20], array_column($first['items'], 'id'));
    same(true, $first['has_more']);
    same(token('{"v":1,"sort_key":0,"id":20}'), $first['next_cursor']);
    $last = $pager->page(2, $first['next_cursor']);
    same([30], array_column($last['items'], 'id'));
    same(false, $last['has_more']);
    same(null, $last['next_cursor']);
});

test('stable transaction has independent tuple order without gaps or duplicates', function (): void {
    $rows = [
        [6, PHP_INT_MAX, 'Synthetic F'], [3, 0, 'Synthetic C'],
        [9, PHP_INT_MIN, 'Synthetic I'], [2, -4, 'Synthetic B'],
        [7, PHP_INT_MAX, 'Synthetic G'], [1, 0, 'Synthetic A'],
        [5, -4, 'Synthetic E'], [4, 2147483648, 'Synthetic D'],
        [8, -2147483649, 'Synthetic H'],
    ];
    $expected = [[PHP_INT_MIN, 9], [-2147483649, 8], [-4, 2], [-4, 5], [0, 1], [0, 3],
        [2147483648, 4], [PHP_INT_MAX, 6], [PHP_INT_MAX, 7]];
    $pdo = memoryItems($rows);
    $pager = new Pager($pdo);
    foreach ([1, 2, 4, 100] as $size) {
        $pdo->beginTransaction();
        try {
            $tuples = [];
            $cursor = null;
            for ($i = 0; $i < 10; $i++) {
                $page = $pager->page($size, $cursor);
                foreach ($page['items'] as $item) {
                    $tuples[] = [$item['sort_key'], $item['id']];
                }
                if (!$page['has_more']) {
                    same(null, $page['next_cursor']);
                    break;
                }
                $last = $page['items'][array_key_last($page['items'])];
                same(token('{"v":1,"sort_key":' . $last['sort_key'] . ',"id":' . $last['id'] . '}'), $page['next_cursor']);
                $cursor = $page['next_cursor'];
            }
            same($expected, $tuples);
            same(9, count(array_unique(array_column($tuples, 1))));
        } finally {
            $pdo->rollBack();
        }
    }
    $beyond = $pager->page(2, token('{"v":1,"sort_key":9223372036854775807,"id":9223372036854775807}'));
    same(['items' => [], 'has_more' => false, 'next_cursor' => null], $beyond);
});

test('cursor continuation works with unobserved intermediate tuple not present in database', function (): void {
    $rows = [[1, 0, 'Synthetic A'], [3, 0, 'Synthetic C'], [5, 1, 'Synthetic E']];
    $pdo = memoryItems($rows);
    $pager = new Pager($pdo);
    // Cursor (0, 2) is between (0, 1) and (0, 3); it does not match any existing row ID
    $cursor = Cursor::encode(0, 2);
    $page = $pager->page(2, $cursor);
    same([3, 5], array_column($page['items'], 'id'));
    same(false, $page['has_more']);
    same(null, $page['next_cursor']);
});

test('boundary page sizes 1 and 100 are accepted and boundary violations throw', function (): void {
    $rows = [[1, 0, 'Synthetic A'], [2, 1, 'Synthetic B']];
    $pager = new Pager(memoryItems($rows));
    $page1 = $pager->page(1);
    same(1, count($page1['items']));
    same(true, $page1['has_more']);
    $page100 = $pager->page(100);
    same(2, count($page100['items']));
    same(false, $page100['has_more']);
    throws(fn() => $pager->page(0));
    throws(fn() => $pager->page(101));
});

test('predicate uses bound integers and SQL-looking titles remain data', function (): void {
    $title = "Synthetic x'); DROP TABLE items;--";
    $pdo = memoryItems([[1, -1, 'Synthetic A'], [2, 0, $title], [3, 0, 'Synthetic C'], [4, 1, 'Synthetic D']]);
    $predicate = KeysetPredicate::after(new CursorPosition(0, 2));
    $statement = $pdo->prepare('SELECT id FROM main.items WHERE ' . $predicate->sql() . ' ORDER BY sort_key ASC, id ASC');
    $predicate->bind($statement);
    $statement->execute();
    same([3, 4], $statement->fetchAll(PDO::FETCH_COLUMN));
    $statement->closeCursor();
    same('1 = 1', KeysetPredicate::after(null)->sql());
    $pager = new Pager($pdo);
    same($title, $pager->page(4)['items'][1]['title']);
    $attack = token('{"v":1,"sort_key":"0); DROP TABLE items;--","id":1}');
    throws(fn() => $pager->page(2, $attack));
    foreach ([0, 101, -1, true, 1.0, '2', '1; DROP TABLE items;--', PHP_INT_MAX] as $bad) {
        throws(fn() => $pager->page($bad));
    }
    same(4, $pdo->query('SELECT count(*) FROM items')->fetchColumn());
});

test('selected invalid data fails closed including lookahead', function (): void {
    foreach ([
        "INSERT INTO items VALUES(0, 0, 'Synthetic')",
        "INSERT INTO items VALUES(-1, 0, 'Synthetic')",
        "INSERT INTO items VALUES(1, 0.5, 'Synthetic')",
        "INSERT INTO items VALUES(1, 'not an integer', 'Synthetic')",
        "INSERT INTO items VALUES(1, 0, X'41')",
        "INSERT INTO items VALUES(1, 0, CAST(X'ff' AS TEXT))",
    ] as $sql) {
        $pdo = memoryItems();
        $pdo->exec($sql);
        throws(fn() => (new Pager($pdo))->page(1));
    }
    $pdo = memoryItems([[1, 0, 'Synthetic good'], [2, 1, str_repeat('x', 1025)]]);
    throws(fn() => (new Pager($pdo))->page(1));
    $valid = memoryItems([[1, 0, str_repeat('a', 1024)]]);
    same(1024, strlen((new Pager($valid))->page(1)['items'][0]['title']));
    $empty = memoryItems([[1, 0, '']]);
    same('', (new Pager($empty))->page(1)['items'][0]['title']);
});

test('UTF-16 databases are rejected before interpreting title blobs as UTF-8', function (): void {
    foreach (['UTF-16le', 'UTF-16be'] as $encoding) {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("PRAGMA encoding = '" . $encoding . "'");
        createItems($pdo, [[1, 0, 'A']]);
        same($encoding, $pdo->query('PRAGMA encoding')->fetchColumn());
        throws(fn() => new Pager($pdo));
    }
});

test('schema rejects views extra columns nullable ordering and noninteger primary keys', function (): void {
    foreach ([
        'CREATE TABLE unrelated(value TEXT)',
        'CREATE VIEW items AS SELECT 1 AS id, 0 AS sort_key, \'Synthetic\' AS title',
        'CREATE TABLE items(id INTEGER PRIMARY KEY, sort_key INTEGER, title TEXT NOT NULL)',
        'CREATE TABLE items(id TEXT PRIMARY KEY, sort_key INTEGER NOT NULL, title TEXT NOT NULL)',
        'CREATE TABLE items(id INTEGER PRIMARY KEY, sort_key INTEGER NOT NULL, title TEXT NOT NULL, extra TEXT)',
        'CREATE TABLE items(id INTEGER PRIMARY KEY DESC, sort_key INTEGER NOT NULL, title TEXT NOT NULL)',
        'CREATE TABLE items(id INTEGER PRIMARY KEY, sort_key INTEGER NOT NULL, title TEXT NOT NULL) WITHOUT ROWID',
    ] as $sql) {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec($sql);
        throws(fn() => new Pager($pdo));
    }
});

test('one WAL read transaction retains its snapshot during other committed mutations', function (): void {
    withDirectory(function (string $directory): void {
        $path = $directory . '/snapshot.sqlite';
        $writer = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        same('wal', $writer->query('PRAGMA journal_mode = WAL')->fetchColumn());
        createItems($writer, [[1, 0, 'Synthetic A'], [2, 0, 'Synthetic B'], [3, 1, 'Synthetic C'], [4, 1, 'Synthetic D']]);
        $reader = SqliteDatabase::openReadOnly($path);
        $reader->beginTransaction();
        $pager = new Pager($reader);
        $first = $pager->page(2);
        same([1, 2], array_column($first['items'], 'id'));
        $writer->exec("DELETE FROM items WHERE id = 3; UPDATE items SET sort_key = -1 WHERE id = 4; INSERT INTO items VALUES(5, 0, 'Synthetic E')");
        $last = $pager->page(2, $first['next_cursor']);
        same([3, 4], array_column($last['items'], 'id'));
        same(null, $last['next_cursor']);
        $reader->commit();
        same([4, 1, 2, 5], array_column($pager->page(10)['items'], 'id'));
        $pager = null;
        $reader = null;
        $writer = null;
    });
});
