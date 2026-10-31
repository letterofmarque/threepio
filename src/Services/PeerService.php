<?php

declare(strict_types=1);

namespace Marque\Threepio\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Marque\Threepio\Enums\BaselineSource;
use Marque\Threepio\Support\PeerAddresses;
use Marque\Threepio\Support\PeerLookup;

/**
 * Redis-backed peer storage service.
 *
 * Redis keys used:
 * - {prefix}peers:{torrent_id} - Hash of peer_id => serialized peer data
 * - {prefix}torrent:{torrent_id}:seeders - Integer counter
 * - {prefix}torrent:{torrent_id}:leechers - Integer counter
 * - {prefix}user:{user_id}:peers - Set of "torrent_id:peer_id" strings (when userId provided)
 * - {prefix}ip:{ip}:sessions - Set of "torrent_id:peer_id" strings (for IP limiting), expiring
 * - {prefix}gone:{torrent_id} - Hash of peer_id => record kept after expiry as a baseline
 *   (only when retainBaselinesFor() is set), expiring
 * - {prefix}swarm:{torrent_id}:uploaded - Total bytes uploaded in swarm
 * - {prefix}swarm:{torrent_id}:downloaded - Total bytes downloaded in swarm
 */
final class PeerService
{
    private string $prefix;

    private int $peerExpiry;

    /**
     * Recovers a peer's last known cumulative counters when Redis has lost them.
     *
     * Redis holds the baseline that deltas are diffed against. If it restarts,
     * that baseline is gone and the next announce has nothing to compare to —
     * so it credits zero, silently, and whatever the peer transferred across
     * the gap is lost with no way to detect it.
     *
     * A tracker that keeps a durable record of announces can recover the
     * baseline from it. Threepio has no such record and cannot depend on the
     * package that does (bloodhound depends on threepio, never the reverse),
     * so this is a seam rather than a lookup: a private tracker supplies a
     * resolver, a public one leaves it null and keeps today's behaviour.
     *
     * @var null|callable(int, string, ?int): (array{uploaded: int, downloaded: int, left?: int, last_action?: int}|null)
     */
    private $baselineResolver = null;

    /**
     * Seconds after its last announce that an expired peer's record is kept as
     * a baseline. 0 deletes it on expiry.
     */
    private int $baselineRetention = 0;

    public function __construct()
    {
        $this->prefix = config('threepio.redis.prefix', 'marque:');
        $this->peerExpiry = (int) config('threepio.peer_expiry', 3600);
    }

    /**
     * Supply a durable fallback for a peer's cumulative counters.
     *
     * Called only when Redis has no record of the peer. Return null when the
     * peer genuinely has no history — a first announce has no baseline, and
     * inventing one would be worse than admitting there isn't one.
     *
     * Consulted after both the swarm and any retained baseline miss, with the
     * announcing user's id: another member's counters are never a baseline.
     * The answer may carry `left` and `last_action` (a Unix timestamp), which
     * give the recovered interval its start; without `last_action` no time is
     * credited for it.
     *
     * @param  callable(int, string, ?int): (array{uploaded: int, downloaded: int, left?: int, last_action?: int}|null)  $resolver
     */
    public function resolveBaselineUsing(callable $resolver): void
    {
        $this->baselineResolver = $resolver;
    }

    /**
     * Keep an expired peer's record as a baseline for this long after its last
     * announce, so a member who comes back without having sent `stopped`
     * (client killed, machine off, network gone) is credited what it moved in
     * between rather than nothing.
     *
     * Only a private tracker wants this: its credit is measured against the
     * baseline. The sweep moves expired records into `gone:{torrent}`; a
     * `stopped` peer is never kept, since `stopped` closes the session.
     */
    public function retainBaselinesFor(int $seconds): void
    {
        $this->baselineRetention = max(0, $seconds);
    }

    /**
     * Get Redis connection.
     */
    private function redis(): Connection
    {
        return Redis::connection(config('threepio.redis.connection', 'default'));
    }

    /**
     * Read a peer once, for everything that judges or credits its announce.
     *
     * The baseline is this member's own record: from the swarm hash (live, or
     * expired and not yet swept), else a retained one, else the resolver. A
     * record of another member is never a baseline (#F15: a peer_id is the
     * client's choice, not an identity).
     */
    public function lookup(int $torrentId, string $peerId, ?int $userId): PeerLookup
    {
        $now = $this->now();
        $stored = $this->find($torrentId, $peerId);

        if ($stored !== null && $this->sameOwner($stored, $userId)) {
            return new PeerLookup($stored, BaselineSource::Swarm, $stored, $this->peerExpiry, $now);
        }

        if ($this->baselineRetention > 0) {
            $retained = $this->decode($this->redis()->hget($this->prefix."gone:{$torrentId}", $peerId));

            if ($retained !== null
                && $this->sameOwner($retained, $userId)
                && $now - (int) $retained['last_action'] <= $this->baselineRetention) {
                return new PeerLookup($retained, BaselineSource::Retained, $stored, $this->peerExpiry, $now);
            }
        }

        if ($this->baselineResolver !== null) {
            $recovered = ($this->baselineResolver)($torrentId, $peerId, $userId);

            if ($recovered !== null) {
                return new PeerLookup($recovered, BaselineSource::Recovered, $stored, $this->peerExpiry, $now);
            }
        }

        return new PeerLookup(null, BaselineSource::None, $stored, $this->peerExpiry, $now);
    }

    /**
     * Run $work while holding this peer's lock; null if another holder kept it.
     *
     * One peer's announces must be read, credited and written one at a time:
     * two at once both read the same record and both credit the interval it
     * opens. A client announcing over IPv4 and IPv6 at the same moment, with
     * one peer ID, does exactly that. The lock is a Redis key set only if
     * absent, with a short expiry so a crashed holder cannot keep it, and it
     * is released only by the holder that set it.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T|null
     */
    public function withPeerLock(int $torrentId, string $peerId, callable $work, int $waitMilliseconds = 2000): mixed
    {
        $redis = $this->redis();
        $key = $this->prefix.'lock:'.$torrentId.':'.bin2hex($peerId);
        $token = bin2hex(random_bytes(8));
        $deadline = microtime(true) + $waitMilliseconds / 1000;

        $acquire = "return redis.call('set', KEYS[1], ARGV[1], 'NX', 'PX', ARGV[2]) and 1 or 0";

        while (! (int) $redis->eval($acquire, 1, $key, $token, 5000)) {
            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(20_000);
        }

        try {
            return $work();
        } finally {
            $redis->eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $key, $token);
        }
    }

    /**
     * Add or update a peer.
     *
     * Pass the lookup the caller already made (anti-cheat reads the same one),
     * so the credit is computed against exactly the baseline that was judged
     * and the peer is not read twice.
     *
     * $ip is the address the announce came from. $ipv4 and $ipv6 are what the
     * client said it has (BEP 7), already vetted by the tracker; null when it
     * said nothing or the tracker doesn't trust what it says. See
     * PeerAddresses for how the record keeps one address per family.
     */
    public function upsertPeer(
        int $torrentId,
        string $peerId,
        ?int $userId,
        string $ip,
        int $port,
        int $uploaded,
        int $downloaded,
        int $left,
        string $userAgent,
        bool $isSeeder,
        ?PeerLookup $lookup = null,
        ?string $ipv4 = null,
        ?string $ipv6 = null,
    ): array {
        $redis = $this->redis();
        $peerKey = $this->prefix."peers:{$torrentId}";
        $lookup ??= $this->lookup($torrentId, $peerId, $userId);
        $now = $lookup->readAt;

        // Swarm bookkeeping follows what is in the hash, whoever owns it: a
        // record there is already in the counters and sets. The baseline is a
        // separate question (see PeerLookup), so a retained or recovered peer
        // has counters to diff against and still joins the swarm as new.
        $stored = $lookup->stored;
        $baseline = $lookup->baseline;

        // The record is keyed by the raw peer ID, which Redis stores as bytes.
        // It is not repeated inside the JSON: BEP 3 lets a peer ID be any 20
        // bytes, and JSON cannot hold bytes that are not UTF-8 (json_encode
        // returned false, so such a peer was never recorded or credited). The
        // user agent is the client's own text and is scrubbed for the same
        // reason.
        $peerData = [
            'user_id' => $userId,
            'ip' => $ip,
        ] + PeerAddresses::merge(
            $stored !== null && $this->sameOwner($stored, $userId) ? $stored : null,
            $ip,
            $ipv4,
            $ipv6,
            $now,
            $this->peerExpiry,
        ) + [
            'port' => $port,
            'uploaded' => $uploaded,
            'downloaded' => $downloaded,
            'left' => $left,
            'user_agent' => mb_scrub($userAgent, 'UTF-8'),
            'is_seeder' => $isSeeder,
            'last_action' => $now,
            'started' => $baseline['started'] ?? $now,
        ];

        $credit = $lookup->credit($uploaded, $downloaded);
        $uploadDelta = $credit['upload_delta'];
        $downloadDelta = $credit['download_delta'];

        if ($stored !== null) {
            if ((bool) $stored['is_seeder'] !== $isSeeder) {
                if ($isSeeder) {
                    $this->incrementSeeders($torrentId);
                    $this->decrementLeechers($torrentId);
                } else {
                    $this->decrementSeeders($torrentId);
                    $this->incrementLeechers($torrentId);
                }
            }

            // Another member's record under this peer_id is replaced; it leaves
            // that member's set.
            if (($stored['user_id'] ?? null) !== null && $stored['user_id'] !== $userId) {
                $redis->srem($this->prefix."user:{$stored['user_id']}:peers", "{$torrentId}:{$peerId}");
            }

            // A peer that moved address leaves its old IP's sessions;
            // removePeer() only knows the current one.
            if (($stored['ip'] ?? $ip) !== $ip) {
                $redis->srem($this->prefix."ip:{$stored['ip']}:sessions", "{$torrentId}:{$peerId}");
            }
        } else {
            $isSeeder ? $this->incrementSeeders($torrentId) : $this->incrementLeechers($torrentId);
        }

        // A retained baseline is used once: the session it belonged to is live
        // again, in the swarm hash.
        if ($lookup->source === BaselineSource::Retained) {
            $redis->hdel($this->prefix."gone:{$torrentId}", $peerId);
        }

        // Membership is (re)asserted on every announce, not just the first.
        // SADD is idempotent, so this costs nothing for a peer already there,
        // and a set that was cleared (or lost) rebuilds from live announces
        // instead of undercounting until every peer has left and rejoined.
        if ($userId !== null) {
            $redis->sadd($this->prefix."user:{$userId}:peers", "{$torrentId}:{$peerId}");
        }

        // Sessions, not peer_ids (#F17): a client that uses one peer_id for
        // every torrent is one session per torrent, and stopping one torrent
        // removes only that one. The TTL, re-asserted here, lets an IP that
        // goes quiet clean itself up; the sweep removes expired sessions.
        $ipKey = $this->prefix."ip:{$ip}:sessions";
        $redis->sadd($ipKey, "{$torrentId}:{$peerId}");
        $redis->expire($ipKey, 2 * $this->peerExpiry);

        // No TTL on the hash, deliberately. A TTL dropped the whole hash once a
        // torrent went quiet, without touching the seeder/leecher counters or
        // the per-IP and per-user sets, which then counted dead peers forever:
        // enough of them and an IP hit hound's cap for good (#10804). Peers
        // leave only through removePeer(), which keeps all of those in step,
        // driven by cleanupExpiredPeers() on the scheduler. Redis deletes the
        // hash itself when its last peer is removed.
        $redis->hset($peerKey, $peerId, json_encode($peerData, JSON_THROW_ON_ERROR));

        if ($uploadDelta > 0) {
            $redis->incrby($this->prefix."swarm:{$torrentId}:uploaded", $uploadDelta);
        }
        if ($downloadDelta > 0) {
            $redis->incrby($this->prefix."swarm:{$torrentId}:downloaded", $downloadDelta);
        }

        // The baseline the deltas were diffed against (null for a peer with no
        // baseline, a real state rather than missing data), when the interval
        // this announce closes began, the peer's state then (a seeder when 0),
        // and the interval capped at peer_expiry. Returned so the caller can
        // persist them: a stored delta cannot be checked without the value it
        // was derived from.
        return $credit + [
            'baseline_source' => $lookup->source->value,
            'was_existing' => $stored !== null,
            'baseline_recovered' => $lookup->source === BaselineSource::Recovered,
            'status_changed' => $stored !== null && (bool) $stored['is_seeder'] !== $isSeeder,
        ];
    }

    /**
     * Remove a peer (on stopped event, or from the sweep).
     *
     * Returns the record removed, which a `stopped` announce diffs against. The
     * record is not retained: `stopped` closes the session.
     */
    public function removePeer(int $torrentId, string $peerId): ?array
    {
        $redis = $this->redis();
        $existingPeer = $this->find($torrentId, $peerId);

        // A retained baseline goes too, whether or not the peer is live:
        // `stopped` closes the session, and a stop credited against a
        // retained record must not leave it to be credited again.
        $redis->hdel($this->prefix."gone:{$torrentId}", $peerId);

        if (! $existingPeer) {
            return null;
        }

        $redis->hdel($this->prefix."peers:{$torrentId}", $peerId);

        if ($existingPeer['is_seeder']) {
            $this->decrementSeeders($torrentId);
        } else {
            $this->decrementLeechers($torrentId);
        }

        if ($existingPeer['user_id'] !== null) {
            $redis->srem(
                $this->prefix."user:{$existingPeer['user_id']}:peers",
                "{$torrentId}:{$peerId}"
            );
        }

        $redis->srem($this->prefix."ip:{$existingPeer['ip']}:sessions", "{$torrentId}:{$peerId}");

        return $existingPeer;
    }

    /**
     * A live peer's data, or null when absent or expired.
     *
     * Reads never mutate: an expired peer stays where it is until the sweep
     * removes it. (Removing it here made a read recurse into removal and back,
     * and made "is it live?" destroy the baseline a returning peer needed.)
     */
    public function getPeer(int $torrentId, string $peerId): ?array
    {
        $peer = $this->find($torrentId, $peerId);

        if ($peer === null || $this->now() - $peer['last_action'] > $this->peerExpiry) {
            return null;
        }

        return $peer;
    }

    /**
     * The stored record for a peer in the swarm hash, whatever its age or owner.
     */
    public function find(int $torrentId, string $peerId): ?array
    {
        return $this->decode($this->redis()->hget($this->prefix."peers:{$torrentId}", $peerId));
    }

    /**
     * Every live peer on a torrent, decoded, keyed by peer_id.
     *
     * One HGETALL, which an announce reads anyway for its peer list; counts by
     * member or by location come from this rather than another round trip.
     *
     * @return array<string, array<string, mixed>>
     */
    public function livePeers(int $torrentId): array
    {
        $now = $this->now();
        $live = [];

        foreach ($this->redis()->hgetall($this->prefix."peers:{$torrentId}") as $peerId => $data) {
            $peer = json_decode($data, true);

            if ($now - $peer['last_action'] <= $this->peerExpiry) {
                $live[(string) $peerId] = $peer;
            }
        }

        return $live;
    }

    /**
     * A member's live peers on every torrent, each with its record.
     *
     * Read from `user:{id}:peers`, then checked against the swarm hash: the
     * set is bookkeeping and can hold a member that no longer applies (a
     * record expired and not yet swept, or one another member now owns under
     * the same peer ID), and the hash is the record. One pipelined round trip
     * for the records, so a seedbox's whole library costs one, not one each.
     *
     * @return list<array{torrent_id: int, peer_id: string, peer: array<string, mixed>}>
     */
    public function peersOfUser(int $userId): array
    {
        $redis = $this->redis();
        $members = [];

        foreach ($redis->smembers($this->prefix."user:{$userId}:peers") as $member) {
            // "{torrent}:{peer_id}": the torrent id has no colon, and the peer
            // ID, any 20 bytes, may.
            [$torrentId, $peerId] = explode(':', (string) $member, 2) + [1 => ''];

            if (ctype_digit($torrentId) && $peerId !== '') {
                $members[] = [(int) $torrentId, $peerId];
            }
        }

        if ($members === []) {
            return [];
        }

        $records = $redis->pipeline(function ($pipe) use ($members) {
            foreach ($members as [$torrentId, $peerId]) {
                $pipe->hget($this->prefix."peers:{$torrentId}", $peerId);
            }
        });

        $now = $this->now();
        $peers = [];

        foreach ($members as $i => [$torrentId, $peerId]) {
            $peer = $this->decode($records[$i] ?? null);

            if ($peer === null || ! $this->sameOwner($peer, $userId) || $now - $peer['last_action'] > $this->peerExpiry) {
                continue;
            }

            $peers[] = ['torrent_id' => $torrentId, 'peer_id' => $peerId, 'peer' => $peer];
        }

        return $peers;
    }

    /**
     * The clock every interval is measured on.
     *
     * Carbon's rather than time(), so it is the same clock the tracker stamps
     * its records with, and tests that travel in time move both.
     */
    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function sameOwner(array $record, ?int $userId): bool
    {
        return ($record['user_id'] ?? null) === $userId;
    }

    private function decode(mixed $data): ?array
    {
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Get peers for announce response.
     *
     * Pass livePeers() when the caller has already read it this announce.
     *
     * @param  array<string, array<string, mixed>>|null  $livePeers
     *                                                               Each peer comes with its address in each family it can be reached on
     *                                                               (`ipv4`, `ipv6`), for TrackerResponse to split into `peers` and
     *                                                               `peers6`; `ip` is the address it last announced from.
     * @return array<array{ip: string, port: int, peer_id: string, ipv4: ?string, ipv6: ?string}>
     */
    public function getPeersForAnnounce(
        int $torrentId,
        string $excludePeerId,
        bool $isSeeder,
        int $limit,
        ?array $livePeers = null,
    ): array {
        $peers = [];
        $now = $this->now();

        foreach ($livePeers ?? $this->livePeers($torrentId) as $peerId => $peer) {
            // Skip the requesting peer
            if ((string) $peerId === $excludePeerId) {
                continue;
            }

            // If requester is seeder, only return leechers
            if ($isSeeder && $peer['is_seeder']) {
                continue;
            }

            $peers[] = [
                'ip' => $peer['ip'],
                'port' => $peer['port'],
                'peer_id' => (string) $peerId,
            ] + PeerAddresses::of($peer, $now, $this->peerExpiry);
        }

        // Shuffle BEFORE cutting to the limit. Cutting first in hash order and
        // shuffling after handed every client the same peers in a new order,
        // so in a swarm larger than the limit most peers were never offered.
        shuffle($peers);

        return array_slice($peers, 0, max(0, $limit));
    }

    /**
     * Get seeder count for a torrent.
     */
    public function getSeeders(int $torrentId): int
    {
        return (int) $this->redis()->get($this->prefix."torrent:{$torrentId}:seeders") ?: 0;
    }

    /**
     * Get leecher count for a torrent.
     */
    public function getLeechers(int $torrentId): int
    {
        return (int) $this->redis()->get($this->prefix."torrent:{$torrentId}:leechers") ?: 0;
    }

    /**
     * Get swarm stats for anti-cheat.
     */
    public function getSwarmStats(int $torrentId): array
    {
        $redis = $this->redis();

        return [
            'total_uploaded' => (int) $redis->get($this->prefix."swarm:{$torrentId}:uploaded") ?: 0,
            'total_downloaded' => (int) $redis->get($this->prefix."swarm:{$torrentId}:downloaded") ?: 0,
            'seeders' => $this->getSeeders($torrentId),
            'leechers' => $this->getLeechers($torrentId),
        ];
    }

    /**
     * Live peers a member has on a torrent, counted from the torrent's hash.
     *
     * Not from `user:{id}:peers`: that set spans every torrent the member is
     * on, so scanning it per announce was O(a seedbox's whole library).
     *
     * @param  array<string, array<string, mixed>>|null  $livePeers  livePeers(), when already read
     */
    public function getUserPeerCountForTorrent(int $userId, int $torrentId, ?array $livePeers = null): int
    {
        return count(array_filter(
            $livePeers ?? $this->livePeers($torrentId),
            fn (array $peer) => ($peer['user_id'] ?? null) === $userId,
        ));
    }

    /**
     * Sessions (torrent, peer_id) announcing from an IP.
     */
    public function getIpSessionCount(string $ip): int
    {
        return (int) $this->redis()->scard($this->prefix."ip:{$ip}:sessions");
    }

    /**
     * Clean up expired peers (run periodically via scheduler).
     *
     * The only path by which an expired peer leaves the swarm. With
     * retainBaselinesFor() set, its record moves to `gone:{torrent}` first.
     */
    public function cleanupExpiredPeers(int $torrentId): int
    {
        $redis = $this->redis();
        $peerKey = $this->prefix."peers:{$torrentId}";
        $goneKey = $this->prefix."gone:{$torrentId}";

        $allPeers = $redis->hgetall($peerKey);
        $now = $this->now();
        $removed = 0;

        $seeders = 0;
        $leechers = 0;

        foreach ($allPeers as $peerId => $data) {
            $peer = json_decode($data, true);

            if ($now - $peer['last_action'] > $this->peerExpiry) {
                // Removed first: removal also clears any retained record,
                // which is right for a stop and wrong for the one kept here.
                $this->removePeer($torrentId, (string) $peerId);
                $removed++;

                if ($this->baselineRetention > 0
                    && $now - $peer['last_action'] <= $this->baselineRetention) {
                    $redis->hset($goneKey, $peerId, $data);
                    $redis->expire($goneKey, $this->baselineRetention);
                }

                continue;
            }

            $peer['is_seeder'] ? $seeders++ : $leechers++;
        }

        // Retained baselines past the retention go; the hash's own TTL covers
        // a torrent that falls silent and is never swept again.
        foreach ($this->baselineRetention > 0 ? $redis->hgetall($goneKey) : [] as $peerId => $data) {
            if ($now - json_decode($data, true)['last_action'] > $this->baselineRetention) {
                $redis->hdel($goneKey, $peerId);
            }
        }

        // Settle the counters to the peers actually present. incr/decr drift
        // stays wrong forever otherwise (a lost decrement, a peer removed
        // twice, keys left by the old hash TTL), and this sweep runs hourly
        // anyway. An announce landing between the read above and these writes
        // can be miscounted until the next sweep; that is the trade for not
        // locking the hot path.
        // A zero is a missing key rather than a stored 0: the sweep visits every
        // torrent in the catalogue, and most have no peers at all.
        foreach (['seeders' => $seeders, 'leechers' => $leechers] as $counter => $count) {
            $key = $this->prefix."torrent:{$torrentId}:{$counter}";
            $count > 0 ? $redis->set($key, $count) : $redis->del($key);
        }

        return $removed;
    }

    private function incrementSeeders(int $torrentId): void
    {
        $this->redis()->incr($this->prefix."torrent:{$torrentId}:seeders");
    }

    private function decrementSeeders(int $torrentId): void
    {
        $this->redis()->decr($this->prefix."torrent:{$torrentId}:seeders");
    }

    private function incrementLeechers(int $torrentId): void
    {
        $this->redis()->incr($this->prefix."torrent:{$torrentId}:leechers");
    }

    private function decrementLeechers(int $torrentId): void
    {
        $this->redis()->decr($this->prefix."torrent:{$torrentId}:leechers");
    }
}
