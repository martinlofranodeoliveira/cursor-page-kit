<?php
declare(strict_types=1);

namespace CursorPageKit;

use PDO;
use PDOException;

final class SqliteDatabase
{
    public static function openReadOnly(string $path): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new PageKitException('PDO_SQLite is required.');
        }
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new PageKitException('An existing regular local SQLite file is required.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new PageKitException('An existing readable regular local SQLite file is required.');
        }
        try {
            // An absolute filesystem path, not an SQLite URI. READONLY never includes CREATE.
            $pdo = new PDO('sqlite:' . $resolved, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 1,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
            self::assertSchema($pdo);
            return $pdo;
        } catch (PDOException $exception) {
            throw new PageKitException('Could not open or inspect the SQLite database.', 0, $exception);
        }
    }

    public static function assertSchema(PDO $pdo): void
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                throw new PageKitException('A PDO_SQLite connection is required.');
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
            // Bounded title reads cast TEXT to BLOB. SQLite uses the database
            // encoding for that cast, so accepting UTF-16 would corrupt UTF-8
            // output. Reject it before any row data is interpreted.
            if ($pdo->query('PRAGMA main.encoding')->fetchColumn() !== 'UTF-8') {
                throw new PageKitException('A UTF-8 SQLite database is required.');
            }
            $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
            if (!is_string($version) || version_compare($version, '3.37.0', '<')) {
                throw new PageKitException('SQLite 3.37+ is required.');
            }
            $statement = $pdo->prepare(
                'SELECT type, wr FROM pragma_table_list WHERE schema = :schema AND name = :name LIMIT 2'
            );
            $statement->bindValue(':schema', 'main', PDO::PARAM_STR);
            $statement->bindValue(':name', 'items', PDO::PARAM_STR);
            $statement->execute();
            $table = $statement->fetch(PDO::FETCH_ASSOC);
            if ($table === false || $table['type'] !== 'table' || (int) $table['wr'] !== 0
                || $statement->fetch(PDO::FETCH_ASSOC) !== false) {
                throw new PageKitException('Unexpected database schema.');
            }
            $statement->closeCursor();
            $columns = $pdo->query("PRAGMA main.table_xinfo('items')");
            $expected = [['id', 'INTEGER', 1], ['sort_key', 'INTEGER', 0], ['title', 'TEXT', 0]];
            foreach ($expected as $index => [$name, $type, $primary]) {
                $column = $columns->fetch(PDO::FETCH_ASSOC);
                if ($column === false || (int) $column['cid'] !== $index || $column['name'] !== $name
                    || strtoupper(trim($column['type'])) !== $type || (int) $column['pk'] !== $primary
                    || (int) $column['hidden'] !== 0 || $column['dflt_value'] !== null
                    || ($index !== 0 && (int) $column['notnull'] !== 1)) {
                    throw new PageKitException('Unexpected database schema.');
                }
            }
            if ($columns->fetch(PDO::FETCH_ASSOC) !== false) {
                throw new PageKitException('Unexpected database schema.');
            }
            $columns->closeCursor();
            // INTEGER PRIMARY KEY DESC is not a rowid alias: it has a separate PK index.
            $primaryIndex = $pdo->query(
                "SELECT 1 FROM pragma_index_list('items', 'main') WHERE origin = 'pk' LIMIT 1"
            );
            if ($primaryIndex->fetchColumn() !== false) {
                throw new PageKitException('Unexpected database schema.');
            }
            $primaryIndex->closeCursor();
        } catch (PDOException $exception) {
            throw new PageKitException('Could not validate the SQLite schema.', 0, $exception);
        }
    }
}
