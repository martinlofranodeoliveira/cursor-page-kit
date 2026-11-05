<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80400 || PHP_INT_SIZE !== 8) {
    fwrite(STDERR, "error: PHP 8.4+ on a 64-bit runtime is required.\n");
    exit(2);
}
try {
    require __DIR__ . '/../src/bootstrap.php';
    exit(\CursorPageKit\Cli::run(array_slice($argv, 1)));
} catch (Throwable) {
    fwrite(STDERR, "error: Could not initialize Cursor Page Kit.\n");
    exit(2);
}
