FROM php:8.4-cli-bookworm
WORKDIR /app
COPY . /app
RUN php -r 'if (PHP_INT_SIZE !== 8 || !extension_loaded("pdo_sqlite")) { fwrite(STDERR, "64-bit PHP with PDO_SQLite required\n"); exit(1); }'
USER 65534:65534
CMD ["php", "bin/cursor-page.php", "demo"]
