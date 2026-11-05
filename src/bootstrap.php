<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80400 || PHP_INT_SIZE !== 8) {
    throw new RuntimeException('Cursor Page Kit requires PHP 8.4+ on a 64-bit runtime.');
}

foreach (['PageKitException', 'CursorPosition', 'Cursor', 'KeysetPredicate', 'SqliteDatabase', 'Pager', 'Demo', 'Cli'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
