<?php
declare(strict_types=1);
$p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r = $p->query('SELECT sqlite_version() AS version, sqlite_source_id() AS source_id')->fetch(PDO::FETCH_ASSOC);
if ($r['version'] !== '3.54.1' || $r['source_id'] !== '2026-10-16 14:45:20 555e07ef33f69a0957d24d8e555b702464a131d6f1607f4c3c1e4551e8e6ca76') { throw new RuntimeException('Unexpected linked SQLite identity'); }
$p->exec("CREATE VIRTUAL TABLE smoke USING fts5(text, tokenize='unicode61 remove_diacritics 2')");
$p->prepare('INSERT INTO smoke VALUES (?)')->execute(['Café']);
$q = $p->prepare('SELECT count(*) FROM smoke WHERE smoke MATCH ?'); $q->execute(['cafe']);
if ((int) $q->fetchColumn() !== 1) { throw new RuntimeException('Actual FTS5 smoke failed'); }
echo json_encode($r + ['fts5_smoke' => true], JSON_THROW_ON_ERROR), PHP_EOL;
