<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "PDO_SQLite is required for tests.\n");
    exit(1);
}

require __DIR__ . '/cursor.php';
require __DIR__ . '/pagination.php';
require __DIR__ . '/cli.php';

$failures = 0;
foreach ($tests as $name => $body) {
    try {
        $body();
        fwrite(STDOUT, 'PASS ' . $name . "\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . "\n");
    }
}
fwrite(STDOUT, count($tests) . ' tests, ' . $failures . " failures\n");
exit($failures === 0 ? 0 : 1);
