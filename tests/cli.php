<?php
declare(strict_types=1);

test('list rejects UTF-16 databases without exposing or modifying their data', function (): void {
    withDirectory(function (string $directory): void {
        foreach (['UTF-16le', 'UTF-16be'] as $encoding) {
            $path = $directory . '/' . $encoding . '.sqlite';
            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec("PRAGMA encoding = '" . $encoding . "'");
            createItems($pdo, [[1, 0, 'PRIVATE_ENCODING_SENTINEL']]);
            $pdo = null;
            $before = hash_file('sha256', $path);
            [$exit, $out, $err] = cli(['list', '--db', $path, '--json']);
            same(2, $exit);
            same('', $out);
            same(false, str_contains($err, 'PRIVATE_ENCODING_SENTINEL'));
            same($before, hash_file('sha256', $path));
        }
    });
});

use CursorPageKit\SqliteDatabase;

test('missing input cannot be created by CLI or read-only API', function (): void {
    withDirectory(function (string $directory): void {
        $path = $directory . '/missing.sqlite';
        [$exit, $out, $err] = cli(['list', '--db', $path]);
        same(2, $exit);
        same('', $out);
        same(true, str_starts_with($err, 'error: '));
        same(false, file_exists($path));
        throws(fn() => SqliteDatabase::openReadOnly($path));
        same(['.', '..'], scandir($directory));
        [$exit] = cli(['list', '--db', $directory]);
        same(2, $exit);
    });
});

test('list leaves a valid database unchanged and OS-level read-only survives query_only OFF', function (): void {
    withDirectory(function (string $directory): void {
        $path = $directory . '/synthetic data.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        createItems($pdo, [[2, 0, 'Synthetic B'], [1, 0, 'Synthetic A'], [3, 8, 'Synthetic C']]);
        $pdo = null;
        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        [$exit, $out, $err] = cli(['list', '--db', $path, '--page-size', '2', '--json']);
        same(0, $exit);
        same('', $err);
        $page = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
        same([1, 2], array_column($page['items'], 'id'));
        same(true, $page['has_more']);
        [$exit, $out, $err] = cli(['list', '--db', $path, '--page-size', '2', '--cursor', $page['next_cursor'], '--json']);
        same(0, $exit);
        same('', $err);
        $last = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
        same([3], array_column($last['items'], 'id'));
        same(null, $last['next_cursor']);
        $reader = SqliteDatabase::openReadOnly($path);
        $reader->exec('PRAGMA query_only = OFF');
        throws(fn() => $reader->exec("UPDATE items SET title = 'Synthetic changed'"), PDOException::class);
        throws(fn() => $reader->exec('CREATE TABLE forbidden(value TEXT)'), PDOException::class);
        $reader = null;
        clearstatcache(true, $path);
        same($hash, hash_file('sha256', $path));
        same($size, filesize($path));
        same(['.', '..', 'synthetic data.sqlite'], scandir($directory));
    });
});

test('list mode on empty database returns empty items list and null next_cursor', function (): void {
    withDirectory(function (string $directory): void {
        $path = $directory . '/empty-items.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        createItems($pdo, []);
        $pdo = null;
        [$exit, $out, $err] = cli(['list', '--db', $path, '--json']);
        same(0, $exit);
        same('', $err);
        $page = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
        same([], $page['items']);
        same(false, $page['has_more']);
        same(null, $page['next_cursor']);
        [$exit, $out, $err] = cli(['list', '--db', $path]);
        same(0, $exit);
        same('', $err);
        same("items=0\nhas_more=false\nnext_cursor=null\n", $out);
    });
});

test('positional database argument is rejected in list mode', function (): void {
    withDirectory(function (string $directory): void {
        $path = $directory . '/positional.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        createItems($pdo, [[1, 0, 'Synthetic A']]);
        $pdo = null;
        [$exit, $out, $err] = cli(['list', $path]);
        same(2, $exit);
        same('', $out);
        same(true, str_starts_with($err, 'error: '));
    });
});

test('unrelated and unrecognized files are rejected without modification', function (): void {
    withDirectory(function (string $directory): void {
        foreach (['unrelated.sqlite', 'not-sqlite.txt', 'empty.sqlite'] as $name) {
            $path = $directory . '/' . $name;
            if ($name === 'unrelated.sqlite') {
                $pdo = new PDO('sqlite:' . $path);
                $pdo->exec('CREATE TABLE unrelated(value TEXT)');
                $pdo = null;
            } else {
                file_put_contents($path, $name === 'empty.sqlite' ? '' : "Synthetic non-database\n");
            }
            $hash = hash_file('sha256', $path);
            [$exit, $out, $err] = cli(['list', '--db', $path, '--json']);
            same(2, $exit);
            same('', $out);
            same(true, str_starts_with($err, 'error: '));
            same($hash, hash_file('sha256', $path));
        }
    });
});

test('CLI usage errors never echo titles or full cursors', function (): void {
    withDirectory(function (string $directory): void {
        $path = $directory . '/safe.sqlite';
        $title = 'Synthetic diagnostic sentinel';
        $pdo = new PDO('sqlite:' . $path);
        createItems($pdo, [[1, 0, $title]]);
        $pdo = null;
        $cursor = token('{"v":1,"sort_key":"Synthetic cursor sentinel","id":1}');
        foreach ([
            [], ['unknown'], ['list'], ['demo', '--db', $path],
            ['list', '--db', $path, '--page-size', '1; DROP TABLE items'],
            ['list', '--db', $path, '--page-size', '01'],
            ['list', '--db', $path, '--page-size', '101'],
            ['list', '--db', $path, '--cursor', $cursor],
            ['list', '--db', $path, '--db', $path],
            ['list', '--db'],
        ] as $args) {
            [$exit, $out, $err] = cli($args);
            same(2, $exit);
            same('', $out);
            same(false, str_contains($err, $title));
            same(false, str_contains($err, $cursor));
            same(false, str_contains($err, $path));
        }
        [$exit, $out, $err] = cli(['--help']);
        same(0, $exit);
        same('', $err);
        same(true, str_contains($out, 'list --db FILE'));
    });
});

test('human titles are terminal-safe quoted data', function (): void {
    withDirectory(function (string $directory): void {
        $path = $directory . '/controls.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        createItems($pdo, [[1, 0, "Synthetic\n\x1b[31m"]]);
        $pdo = null;
        [$exit, $out, $err] = cli(['list', '--db', $path]);
        same(0, $exit);
        same('', $err);
        same(false, str_contains($out, "\x1b"));
        same(true, str_contains($out, 'Synthetic\\n\\u001b[31m'));
    });
});

test('offline demo outputs deterministic pages and removes all temporary files', function (): void {
    withDirectory(function (string $directory): void {
        $environment = array_replace(getenv(), ['TMPDIR' => $directory]);
        [$exit, $out, $err] = cli(['demo'], $environment);
        same(0, $exit);
        same('', $err);
        same("Cursor Page Kit: synthetic snapshot demo\npage=1 ids=9,2,4 has_more=true\npage=2 ids=1,3,6 has_more=true\npage=3 ids=5,7,8 has_more=false\ntotal_items=9 unique_items=9\n", $out);
        same(['.', '..'], scandir($directory));
        [$exit, $out, $err] = cli(['demo', '--json'], $environment);
        same(0, $exit);
        same('', $err);
        $result = json_decode($out, true, 10, JSON_THROW_ON_ERROR);
        same(9, $result['total_items']);
        same(9, $result['unique_items']);
        same([[9, 2, 4], [1, 3, 6], [5, 7, 8]], array_map(fn(array $page): array => array_column($page['items'], 'id'), $result['pages']));
        same(null, $result['pages'][2]['next_cursor']);
        same(['.', '..'], scandir($directory));
    });
});
