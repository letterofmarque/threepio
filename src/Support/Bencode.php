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
        $value = self::decodeValue($data, $offset);

        // One value, and nothing after it but whitespace: a downloaded .torrent
        // can pick up a trailing newline, and refusing that would break uploads
        // that real clients accept. Anything else trailing is not bencode.
        if (rtrim(substr($data, $offset), " \t\r\n") !== '') {
            throw new InvalidArgumentException('Unexpected data after the bencoded value');
        }

        return $value;
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
     * @param  array<int, mixed>  $value
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
     * @param  array<string, mixed>  $value
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

        return match (true) {
            $data[$offset] === 'i' => self::decodeInt($data, $offset),
            $data[$offset] === 'l' => self::decodeList($data, $offset),
            $data[$offset] === 'd' => self::decodeDict($data, $offset),
            ctype_digit($data[$offset]) => self::decodeString($data, $offset),
            default => throw new InvalidArgumentException("Unknown type byte at offset $offset"),
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

        $digits = substr($data, $offset, $colonPos - $offset);

        // A length is plain digits, with no leading zero unless it is zero.
        if (! preg_match('/^(0|[1-9][0-9]*)$/', $digits)) {
            throw new InvalidArgumentException("Invalid string length at offset $offset");
        }

        $length = (int) $digits;
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

        // Digits only, an optional minus, no leading zeros (so no -0), and it
        // must fit: (int) would otherwise clamp an overflow to PHP_INT_MAX.
        if (! preg_match('/^(0|-?[1-9][0-9]*)$/', $value) || (string) (int) $value !== $value) {
            throw new InvalidArgumentException("Invalid integer at offset $offset");
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

        while (self::peek($data, $offset) !== 'e') {
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

        while (self::peek($data, $offset) !== 'e') {
            $key = self::decodeString($data, $offset);
            $dict[$key] = self::decodeValue($data, $offset);
        }

        $offset++; // Skip 'e'

        return $dict;
    }

    /**
     * The byte at the offset, or a clean failure where an unterminated list or
     * dictionary runs off the end (it used to surface as a PHP warning).
     */
    private static function peek(string $data, int $offset): string
    {
        if ($offset >= strlen($data)) {
            throw new InvalidArgumentException('Unexpected end of data');
        }

        return $data[$offset];
    }
}
