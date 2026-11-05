<?php
declare(strict_types=1);

use CursorPageKit\PageKitException;

$tests = [];

function test(string $name, Closure $body): void
{
    global $tests;
    if (isset($tests[$name])) {
        throw new RuntimeException('Duplicate test name.');
    }
    $tests[$name] = $body;
}

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', received ' . var_export($actual, true));
    }
}

function throws(callable $body, string $class = PageKitException::class): Throwable
{
    try {
        $body();
    } catch (Throwable $exception) {
        if (!$exception instanceof $class) {
            throw new RuntimeException('Unexpected exception: ' . $exception::class, 0, $exception);
        }
        return $exception;
    }
    throw new RuntimeException('Expected exception was not thrown.');
}

/** Independent fixture encoder, intentionally without library validation. */
function token(string $json): string
{
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function createItems(PDO $pdo, array $rows = []): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE items(id INTEGER PRIMARY KEY, sort_key INTEGER NOT NULL, title TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX items_order ON items(sort_key, id)');
    $insert = $pdo->prepare('INSERT INTO items(id, sort_key, title) VALUES(:id, :key, :title)');
    foreach ($rows as [$id, $key, $title]) {
        $insert->bindValue(':id', $id, PDO::PARAM_INT);
        $insert->bindValue(':key', $key, PDO::PARAM_INT);
        $insert->bindValue(':title', $title, PDO::PARAM_STR);
        $insert->execute();
    }
}

function memoryItems(array $rows = []): PDO
{
    $pdo = new PDO('sqlite::memory:');
    createItems($pdo, $rows);
    return $pdo;
}

/** Cleanup is restricted to a new private test directory; it never recurses. */
function withDirectory(callable $body): void
{
    $path = tempnam(sys_get_temp_dir(), 'cursor-kit-test-');
    if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
        throw new RuntimeException('Could not allocate test directory.');
    }
    try {
        $body($path);
    } finally {
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $file = $path . '/' . $name;
                if (is_dir($file) || !unlink($file)) {
                    throw new RuntimeException('Unexpected test directory entry.');
                }
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Could not remove test directory.');
        }
    }
}

/** Bounded capture and deadline; arguments bypass the shell. */
function cli(array $args, ?array $environment = null): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../bin/cursor-page.php', ...$args],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start CLI.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 10;
    $finished = false;
    try {
        while (true) {
            $stdout .= stream_get_contents($pipes[1], 8192);
            $stderr .= stream_get_contents($pipes[2], 8192);
            if (strlen($stdout) + strlen($stderr) > 65536) {
                throw new RuntimeException('CLI exceeded capture limit.');
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1], 65536);
                $stderr .= stream_get_contents($pipes[2], 65536);
                if (strlen($stdout) + strlen($stderr) > 65536) {
                    throw new RuntimeException('CLI exceeded capture limit.');
                }
                $finished = true;
                return [$status['exitcode'], $stdout, $stderr];
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('CLI exceeded deadline.');
            }
            usleep(10000);
        }
    } finally {
        if (!$finished) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}
