<?php

declare(strict_types=1);

namespace Marque\Threepio\Support;

use InvalidArgumentException;

/**
 * Bencode encoder/decoder for BitTorrent protocol.
 *
 * Supports encoding/decoding of:
 * - Strings: <length>:<contents>
 * - Integers: i<number>e
 * - Lists: l<contents>e
 * - Dictionaries: d<contents>e
 */
final class Bencode
{
    /**
     * Encode a value to bencode format.
     */
    public static function encode(mixed $value): string
    {
        return match (true) {
            is_int($value) => self::encodeInt($value),
            is_string($value) => self::encodeString($value),
            is_array($value) && array_is_list($value) => self::encodeList($value),
            is_array($value) => self::encodeDict($value),
            default => throw new InvalidArgumentException('Cannot bencode type: '.gettype($value)),
        };
    }

    /**
     * Decode a bencoded string.
     *
     * @return mixed The decoded value
     */
    public static function decode(string $data): mixed
    {
        $offset = 0;

        return self::decodeValue($data, $offset);
    }

    /**
     * Encode a string.
     */
    private static function encodeString(string $value): string
    {
        return strlen($value).':'.$value;
    }

    /**
     * Encode an integer.
     */
    private static function encodeInt(int $value): string
    {
        return 'i'.$value.'e';
    }

    /**
     * Encode a list (sequential array).
     *
     * @param array<int, mixed> $value
     */
    private static function encodeList(array $value): string
    {
        $encoded = 'l';

        foreach ($value as $item) {
            $encoded .= self::encode($item);
        }

        return $encoded.'e';
    }

    /**
     * Encode a dictionary (associative array).
     * Keys must be sorted alphabetically per the spec.
     *
     * @param array<string, mixed> $value
     */
    private static function encodeDict(array $value): string
    {
        // Keys must be sorted
        ksort($value, SORT_STRING);

        $encoded = 'd';

        foreach ($value as $key => $item) {
            $encoded .= self::encodeString((string) $key);
            $encoded .= self::encode($item);
        }

        return $encoded.'e';
    }

    /**
     * Decode a value at the given offset.
     */
    private static function decodeValue(string $data, int &$offset): mixed
    {
        if ($offset >= strlen($data)) {
            throw new InvalidArgumentException('Unexpected end of data');
        }

        return match ($data[$offset]) {
            'i' => self::decodeInt($data, $offset),
            'l' => self::decodeList($data, $offset),
            'd' => self::decodeDict($data, $offset),
            default => self::decodeString($data, $offset),
        };
    }

    /**
     * Decode a string.
     */
    private static function decodeString(string $data, int &$offset): string
    {
        $colonPos = strpos($data, ':', $offset);

        if ($colonPos === false) {
            throw new InvalidArgumentException('Invalid string encoding');
        }

        $length = (int) substr($data, $offset, $colonPos - $offset);
        $offset = $colonPos + 1;
        $value = substr($data, $offset, $length);

        if (strlen($value) !== $length) {
            throw new InvalidArgumentException('String length mismatch');
        }

        $offset += $length;

        return $value;
    }

    /**
     * Decode an integer.
     */
    private static function decodeInt(string $data, int &$offset): int
    {
        $offset++; // Skip 'i'

        $endPos = strpos($data, 'e', $offset);

        if ($endPos === false) {
            throw new InvalidArgumentException('Invalid integer encoding');
        }

        $value = substr($data, $offset, $endPos - $offset);

        // Validate integer format (no leading zeros except for 0 itself)
        if ($value === '-0' || ($value[0] === '0' && strlen($value) > 1)) {
            throw new InvalidArgumentException('Invalid integer format');
        }

        $offset = $endPos + 1;

        return (int) $value;
    }

    /**
     * Decode a list.
     *
     * @return array<int, mixed>
     */
    private static function decodeList(string $data, int &$offset): array
    {
        $offset++; // Skip 'l'

        $list = [];

        while ($data[$offset] !== 'e') {
            $list[] = self::decodeValue($data, $offset);
        }

        $offset++; // Skip 'e'

        return $list;
    }

    /**
     * Decode a dictionary.
     *
     * @return array<string, mixed>
     */
    private static function decodeDict(string $data, int &$offset): array
    {
        $offset++; // Skip 'd'

        $dict = [];

        while ($data[$offset] !== 'e') {
            $key = self::decodeString($data, $offset);
            $dict[$key] = self::decodeValue($data, $offset);
        }

        $offset++; // Skip 'e'

        return $dict;
    }
}
