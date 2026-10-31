<?php

declare(strict_types=1);

namespace Marque\Threepio\Support;

/**
 * A peer's addresses, one per family (BEP 7).
 *
 * A peer used to have one address, the one its last announce came from. A
 * dual-stack client announces over each family in turn under one peer ID
 * (libtorrent announces once per listen interface), so that address flipped
 * between IPv4 and IPv6, and the peer was handed out only in the family it
 * last used. Each family is kept with when it was last seen: an announce over
 * one refreshes that family and leaves the other alone, and a family not seen
 * within the peer expiry is dropped as stale.
 *
 * The record's `ip` stays the address the last announce came from. That is
 * what the tracker judges and records; these are where the peer can be
 * reached.
 */
final class PeerAddresses
{
    /**
     * An IPv4-mapped IPv6 address (`::ffff:1.2.3.4`) as the IPv4 address it
     * carries; anything else unchanged.
     */
    public static function normalise(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return $ip;
    }

    /**
     * 4, 6, or null for something that is not an address.
     */
    public static function family(string $ip): ?int
    {
        $packed = @inet_pton(self::normalise($ip));

        return match ($packed === false ? 0 : strlen($packed)) {
            4 => 4,
            16 => 6,
            default => null,
        };
    }

    /**
     * An address a client says it has (`ipv4=` / `ipv6=`), or null if it is
     * not one the tracker should hand out.
     *
     * BEP 7 allows an endpoint as well as an address (`1.2.3.4:6881`,
     * `[2001:db8::1]:6881`). The port is dropped: a peer has one port, the one
     * its announce gave. With $publicOnly, a private or reserved address is
     * refused, the same rule the connecting address is held to.
     */
    public static function announced(?string $value, int $family, bool $publicOnly): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $value, $m) === 1) {
            $value = $m[1];
        } elseif ($family === 4 && preg_match('/^([^:]+):\d+$/', $value, $m) === 1) {
            $value = $m[1];
        }

        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $value = self::normalise($value);

        if (self::family($value) !== $family) {
            return null;
        }

        if ($publicOnly && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return (string) inet_ntop((string) inet_pton($value));
    }

    /**
     * The address fields a peer's record holds after an announce.
     *
     * Starts from the stored record's families still fresh at $now, then what
     * the client announced, then the address the announce came from.
     *
     * An announced address fills only the family the announce did not arrive
     * over: the connecting address is the one thing the tracker saw for
     * itself, and a claim never replaces it. Each family also records whether
     * a connection was seen from it (`ipv4_connected`, `ipv6_connected`).
     * An announced address is where a peer can be reached, never proof of
     * where it is, so anything that judges location reads connected() only.
     *
     * @param  array<string, mixed>|null  $stored  this peer's record, if it has one
     * @return array{ipv4: ?string, ipv4_at: ?int, ipv4_connected: bool, ipv6: ?string, ipv6_at: ?int, ipv6_connected: bool}
     */
    public static function merge(?array $stored, string $ip, ?string $ipv4, ?string $ipv6, int $now, int $expiry): array
    {
        $fields = [
            'ipv4' => null, 'ipv4_at' => null, 'ipv4_connected' => false,
            'ipv6' => null, 'ipv6_at' => null, 'ipv6_connected' => false,
        ];

        if ($stored !== null) {
            foreach ([4, 6] as $family) {
                [$address, $at, $connected] = self::stored($stored, $family);

                if ($address !== null && $now - $at <= $expiry) {
                    $fields["ipv{$family}"] = $address;
                    $fields["ipv{$family}_at"] = $at;
                    $fields["ipv{$family}_connected"] = $connected;
                }
            }
        }

        $ip = self::normalise($ip);
        $connectedFamily = self::family($ip);

        foreach ([4 => $ipv4, 6 => $ipv6] as $family => $address) {
            if ($address === null || $family === $connectedFamily || self::family($address) !== $family) {
                continue;
            }

            // The same address a connection was already seen from stays a
            // connected one.
            $fields["ipv{$family}_connected"] = $fields["ipv{$family}_connected"] && $fields["ipv{$family}"] === $address;
            $fields["ipv{$family}"] = $address;
            $fields["ipv{$family}_at"] = $now;
        }

        if ($connectedFamily !== null) {
            $fields["ipv{$connectedFamily}"] = $ip;
            $fields["ipv{$connectedFamily}_at"] = $now;
            $fields["ipv{$connectedFamily}_connected"] = true;
        }

        return $fields;
    }

    /**
     * Where a stored peer can be reached: its IPv4 and IPv6 address, each
     * null when it has none fresh at $now.
     *
     * A record written before the families were kept has only `ip`, which
     * counts for its own family as of the peer's last announce.
     *
     * @param  array<string, mixed>  $peer
     * @return array{ipv4: ?string, ipv6: ?string}
     */
    public static function of(array $peer, ?int $now = null, ?int $expiry = null): array
    {
        $addresses = ['ipv4' => null, 'ipv6' => null];

        foreach ([4, 6] as $family) {
            [$address, $at] = self::stored($peer, $family);

            if ($address !== null && ($now === null || $expiry === null || $now - $at <= $expiry)) {
                $addresses["ipv{$family}"] = $address;
            }
        }

        return $addresses;
    }

    /**
     * The addresses a stored peer was seen connecting from, each null when it
     * has none fresh at $now: what a tracker may judge its location by.
     *
     * An address it only announced is left out, since a client can announce
     * any address it likes. A record written before this was kept has only
     * `ip`, which it connected from.
     *
     * @param  array<string, mixed>  $peer
     * @return array{ipv4: ?string, ipv6: ?string}
     */
    public static function connected(array $peer, int $now, int $expiry): array
    {
        $addresses = ['ipv4' => null, 'ipv6' => null];

        foreach ([4, 6] as $family) {
            [$address, $at, $connected] = self::stored($peer, $family);

            if ($address !== null && $connected && $now - $at <= $expiry) {
                $addresses["ipv{$family}"] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @param  array<string, mixed>  $peer
     * @return array{0: ?string, 1: int, 2: bool}
     */
    private static function stored(array $peer, int $family): array
    {
        if (array_key_exists("ipv{$family}", $peer)) {
            $address = $peer["ipv{$family}"];

            return [
                $address === null ? null : (string) $address,
                (int) ($peer["ipv{$family}_at"] ?? $peer['last_action'] ?? 0),
                (bool) ($peer["ipv{$family}_connected"] ?? true),
            ];
        }

        $ip = isset($peer['ip']) ? self::normalise((string) $peer['ip']) : null;

        return $ip !== null && self::family($ip) === $family
            ? [$ip, (int) ($peer['last_action'] ?? 0), true]
            : [null, 0, false];
    }
}
