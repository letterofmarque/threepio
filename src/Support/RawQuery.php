<?php

declare(strict_types=1);

namespace Marque\Threepio\Support;

use Illuminate\Http\Request;

/**
 * The announce or scrape query string as the client sent it.
 *
 * `info_hash` and `peer_id` are 20 raw bytes, percent-encoded. Two things
 * between the client and `$request->get()` break them:
 *
 * - Laravel's TrimStrings middleware, global by default, trims every query
 *   value, and PHP's trim() strips NUL, space, tab, newline, carriage return
 *   and vertical tab. A hash or ID starting or ending with one of those six
 *   bytes (about one torrent in twenty) loses it, and the announce is refused.
 * - PHP's query parsing keeps only the last of a repeated key, and a scrape
 *   for several torrents sends `info_hash=...&info_hash=...`.
 *
 * Read the binary parameters from here. The raw query string is left alone by
 * both.
 */
final class RawQuery
{
    /**
     * @param  array<string, list<string>>  $values
     */
    private function __construct(private readonly array $values) {}

    public static function of(Request $request): self
    {
        $values = [];

        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);

            // `info_hash[]=` is how PHP-minded clients spell a repeated key.
            if (str_ends_with($key, '[]')) {
                $key = substr($key, 0, -2);
            }

            $values[$key][] = urldecode($value);
        }

        return new self($values);
    }

    /**
     * The first value sent for a key, or null when it was not sent.
     */
    public function first(string $key): ?string
    {
        return $this->values[$key][0] ?? null;
    }

    /**
     * Every value sent for a key, in order.
     *
     * @return list<string>
     */
    public function all(string $key): array
    {
        return $this->values[$key] ?? [];
    }
}
