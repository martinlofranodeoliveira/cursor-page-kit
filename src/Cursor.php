<?php
declare(strict_types=1);

namespace CursorPageKit;

final class Cursor
{
    public const MAX_LENGTH = 128;

    public static function encode(mixed $sortKey, mixed $id): string
    {
        $position = new CursorPosition($sortKey, $id);
        return self::transport(self::canonical($position));
    }

    public static function decode(mixed $token): CursorPosition
    {
        if (!is_string($token) || $token === '' || strlen($token) > self::MAX_LENGTH
            || preg_match('/\A[A-Za-z0-9_-]+\z/', $token) !== 1
            || strlen($token) % 4 === 1) {
            throw new PageKitException('Invalid cursor.');
        }
        $padding = (4 - strlen($token) % 4) % 4;
        $bytes = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', $padding), true);
        if ($bytes === false || self::transport($bytes) !== $token) {
            throw new PageKitException('Invalid cursor.');
        }
        try {
            $value = json_decode($bytes, true, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new PageKitException('Invalid cursor.');
        }
        if (!is_array($value) || array_keys($value) !== ['v', 'sort_key', 'id']
            || $value['v'] !== 1 || !is_int($value['sort_key'])
            || !is_int($value['id']) || $value['id'] < 1) {
            throw new PageKitException('Invalid cursor.');
        }
        $position = new CursorPosition($value['sort_key'], $value['id']);
        // Byte equality also rejects duplicate keys, whitespace and alternate number spellings.
        if (self::canonical($position) !== $bytes) {
            throw new PageKitException('Invalid cursor.');
        }
        return $position;
    }

    private static function canonical(CursorPosition $position): string
    {
        return '{"v":1,"sort_key":' . $position->sortKey . ',"id":' . $position->id . '}';
    }

    private static function transport(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
