<?php
declare(strict_types=1);

use CursorPageKit\Cursor;
use CursorPageKit\CursorPosition;

test('known v1 bytes and token', function (): void {
    $known = 'eyJ2IjoxLCJzb3J0X2tleSI6MCwiaWQiOjF9';
    same($known, Cursor::encode(0, 1));
    $position = Cursor::decode($known);
    same(0, $position->sortKey);
    same(1, $position->id);
});

test('signed 64-bit boundary fixtures', function (): void {
    foreach ([
        ['-9223372036854775808', PHP_INT_MIN, '1', 1],
        ['-2147483649', -2147483649, '2', 2],
        ['0', 0, '9223372036854775807', PHP_INT_MAX],
        ['2147483648', 2147483648, '3', 3],
        ['9223372036854775807', PHP_INT_MAX, '9223372036854775807', PHP_INT_MAX],
    ] as [$keyText, $key, $idText, $id]) {
        $raw = '{"v":1,"sort_key":' . $keyText . ',"id":' . $idText . '}';
        same(token($raw), Cursor::encode($key, $id));
        $position = Cursor::decode(token($raw));
        same([$key, $id], [$position->sortKey, $position->id]);
    }
});

test('reject malformed cursor JSON and noncanonical shapes', function (): void {
    $bad = [
        '', 'null', '[]', '{}', '{',
        '{"v":2,"sort_key":0,"id":1}',
        '{"v":0,"sort_key":0,"id":1}',
        '{"v":true,"sort_key":0,"id":1}',
        '{"v":1.0,"sort_key":0,"id":1}',
        '{"v":1,"sort_key":0}',
        '{"v":1,"id":1}',
        '{"sort_key":0,"id":1}',
        '{"v":1,"sort_key":0,"id":1,"extra":0}',
        '{"v":1,"sort_key":0,"id":1,"id":1}',
        '{"v":2,"v":1,"sort_key":0,"id":1}',
        '{"id":1,"v":1,"sort_key":0}',
        '{"v":1, "sort_key":0,"id":1}',
        '{"v":1,"sort_key":-0,"id":1}',
        '{"v":1,"sort_key":01,"id":1}',
        '{"v":1,"sort_key":1.0,"id":1}',
        '{"v":1,"sort_key":1e0,"id":1}',
        '{"v":1,"sort_key":true,"id":1}',
        '{"v":1,"sort_key":"0","id":1}',
        '{"v":1,"sort_key":[],"id":1}',
        '{"v":1,"sort_key":0,"id":true}',
        '{"v":1,"sort_key":0,"id":1.0}',
        '{"v":1,"sort_key":0,"id":"1"}',
        '{"v":1,"sort_key":0,"id":0}',
        '{"v":1,"sort_key":0,"id":-1}',
        '{"v":1,"sort_key":9223372036854775808,"id":1}',
        '{"v":1,"sort_key":-9223372036854775809,"id":1}',
        '{"v":1,"sort_key":0,"id":9223372036854775808}',
        '{"v":1,"sort_key":0,"id":-9223372036854775808}',
        '{"v":1,"sort_key":0.5,"id":1}',
        '{"v":1,"sort_key":0,"id":1.5}',
        '{"v":1,"sort_key":0,"id":1}' . "\n",
        "\xff",
    ];
    foreach ($bad as $raw) {
        throws(fn() => Cursor::decode(token($raw)));
    }
});

test('strict base64url bounds and pad bits', function (): void {
    $valid = token('{"v":1,"sort_key":0,"id":10}');
    same('Q', substr($valid, -1));
    foreach ([
        '', 'A', '***', 'ab+c', 'ab/c', $valid . '=', $valid . "\n", ' ' . $valid,
        substr($valid, 0, -1) . 'R', str_repeat('A', 129), str_repeat('A', 4096),
        null, true, 1, 'invalid@token!', $valid . 'A',
    ] as $bad) {
        throws(fn() => Cursor::decode($bad));
    }
});

test('PHP scalar confusion is rejected before encoding', function (): void {
    foreach ([true, false, 1.0, 0.0, '1', null, [], INF, NAN] as $bad) {
        throws(fn() => Cursor::encode($bad, 1));
        throws(fn() => Cursor::encode(0, $bad));
        throws(fn() => new CursorPosition($bad, 1));
    }
    foreach ([0, -1, PHP_INT_MIN] as $bad) {
        throws(fn() => Cursor::encode(0, $bad));
    }
});
