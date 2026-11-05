<?php
declare(strict_types=1);

namespace CursorPageKit;

final class Cli
{
    /** @param list<string> $args Arguments excluding the executable name. */
    public static function run(array $args): int
    {
        try {
            if ($args === ['--help']) {
                self::write("Usage:\n  php bin/cursor-page.php demo [--json]\n  php bin/cursor-page.php list --db FILE [--page-size 1..100] [--cursor TOKEN] [--json]\n");
                return 0;
            }
            $command = array_shift($args);
            if ($command !== 'demo' && $command !== 'list') {
                throw new PageKitException('Expected demo or list; use --help for usage.');
            }
            $options = [];
            for ($i = 0; $i < count($args); $i++) {
                $key = $args[$i];
                $allowed = $command === 'demo' ? ['--json'] : ['--json', '--db', '--page-size', '--cursor'];
                if (!in_array($key, $allowed, true) || array_key_exists($key, $options)) {
                    throw new PageKitException('Unknown or repeated option; use --help for usage.');
                }
                if ($key === '--json') {
                    $options[$key] = true;
                } else {
                    if (!isset($args[$i + 1])) {
                        throw new PageKitException('An option value is missing.');
                    }
                    $options[$key] = $args[++$i];
                }
            }
            if ($command === 'demo') {
                $result = Demo::run();
                if (isset($options['--json'])) {
                    self::write(self::json($result));
                } else {
                    $output = "Cursor Page Kit: synthetic snapshot demo\n";
                    foreach ($result['pages'] as $index => $page) {
                        $output .= 'page=' . ($index + 1) . ' ids='
                            . implode(',', array_column($page['items'], 'id'))
                            . ' has_more=' . ($page['has_more'] ? 'true' : 'false') . "\n";
                    }
                    $output .= 'total_items=' . $result['total_items']
                        . ' unique_items=' . $result['unique_items'] . "\n";
                    self::write($output);
                }
                return 0;
            }
            if (!isset($options['--db']) || $options['--db'] === '') {
                throw new PageKitException('list requires an explicitly named database file.');
            }
            $rawSize = $options['--page-size'] ?? '20';
            if (preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/', $rawSize) !== 1) {
                throw new PageKitException('Page size must be a decimal integer from 1 to 100.');
            }
            $cursor = $options['--cursor'] ?? null;
            if ($cursor !== null) {
                Cursor::decode($cursor);
            }
            $pdo = SqliteDatabase::openReadOnly($options['--db']);
            $pdo->beginTransaction();
            try {
                $page = (new Pager($pdo))->page((int) $rawSize, $cursor);
                $pdo->commit();
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            if (isset($options['--json'])) {
                self::write(self::json($page));
            } else {
                $output = 'items=' . count($page['items']) . "\n";
                foreach ($page['items'] as $item) {
                    // JSON quoting prevents control characters from becoming terminal commands.
                    $output .= 'id=' . $item['id'] . ' sort_key=' . $item['sort_key']
                        . ' title=' . json_encode($item['title'], JSON_THROW_ON_ERROR) . "\n";
                }
                $output .= 'has_more=' . ($page['has_more'] ? 'true' : 'false') . "\n"
                    . 'next_cursor=' . ($page['next_cursor'] ?? 'null') . "\n";
                self::write($output);
            }
            return 0;
        } catch (PageKitException $exception) {
            @fwrite(STDERR, 'error: ' . $exception->getMessage() . "\n");
            return 2;
        } catch (\Throwable) {
            // Never expose PDO messages, paths, row contents, tokens or stack traces.
            @fwrite(STDERR, "error: Operation failed.\n");
            return 2;
        }
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function write(string $output): void
    {
        if (@fwrite(STDOUT, $output) !== strlen($output)) {
            throw new PageKitException('Could not write output.');
        }
    }
}
