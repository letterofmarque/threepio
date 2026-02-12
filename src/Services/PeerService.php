<?php

declare(strict_types=1);

namespace Marque\Threepio\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed peer storage service.
 *
 * Redis keys used:
 * - {prefix}peers:{torrent_id} - Hash of peer_id => serialized peer data
 * - {prefix}torrent:{torrent_id}:seeders - Integer counter
 * - {prefix}torrent:{torrent_id}:leechers - Integer counter
 * - {prefix}user:{user_id}:peers - Set of "torrent_id:peer_id" strings (when userId provided)
 * - {prefix}ip:{ip}:peers - Set of peer_ids (for IP limiting)
 * - {prefix}swarm:{torrent_id}:uploaded - Total bytes uploaded in swarm
 * - {prefix}swarm:{torrent_id}:downloaded - Total bytes downloaded in swarm
 */
final class PeerService
{
    private string $prefix;

    private int $peerExpiry;

    public function __construct()
    {
        $this->prefix = config('threepio.redis.prefix', 'marque:');
        $this->peerExpiry = (int) config('threepio.peer_expiry', 3600);
    }

    /**
     * Get Redis connection.
     */
    private function redis(): Connection
    {
        return Redis::connection(config('threepio.redis.connection', 'default'));
    }

    /**
     * Add or update a peer.
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
    ): array {
        $redis = $this->redis();
        $peerKey = $this->prefix."peers:{$torrentId}";
        $existingPeer = $this->getPeer($torrentId, $peerId);

        $peerData = [
            'peer_id' => $peerId,
            'user_id' => $userId,
            'ip' => $ip,
            'port' => $port,
            'uploaded' => $uploaded,
            'downloaded' => $downloaded,
            'left' => $left,
            'user_agent' => $userAgent,
            'is_seeder' => $isSeeder,
            'last_action' => time(),
            'started' => $existingPeer['started'] ?? time(),
        ];

        // Calculate deltas for stats tracking
        $uploadDelta = 0;
        $downloadDelta = 0;

        if ($existingPeer) {
            $uploadDelta = max(0, $uploaded - ($existingPeer['uploaded'] ?? 0));
            $downloadDelta = max(0, $downloaded - ($existingPeer['downloaded'] ?? 0));

            // Update seeder/leecher counts if status changed
            if ($existingPeer['is_seeder'] !== $isSeeder) {
                if ($isSeeder) {
                    $this->incrementSeeders($torrentId);
                    $this->decrementLeechers($torrentId);
                } else {
                    $this->decrementSeeders($torrentId);
                    $this->incrementLeechers($torrentId);
                }
            }
        } else {
            // New peer
            if ($isSeeder) {
                $this->incrementSeeders($torrentId);
            } else {
                $this->incrementLeechers($torrentId);
            }

            // Track user's active peers (only for authenticated trackers)
            if ($userId !== null) {
                $redis->sadd($this->prefix."user:{$userId}:peers", "{$torrentId}:{$peerId}");
            }

            // Track IP's active peers
            $redis->sadd($this->prefix."ip:{$ip}:peers", $peerId);
        }

        // Store peer data with TTL
        $redis->hset($peerKey, $peerId, json_encode($peerData));
        $redis->expire($peerKey, $this->peerExpiry * 2); // Key expiry longer than peer expiry

        // Update swarm totals
        if ($uploadDelta > 0) {
            $redis->incrby($this->prefix."swarm:{$torrentId}:uploaded", $uploadDelta);
        }
        if ($downloadDelta > 0) {
            $redis->incrby($this->prefix."swarm:{$torrentId}:downloaded", $downloadDelta);
        }

        return [
            'upload_delta' => $uploadDelta,
            'download_delta' => $downloadDelta,
            'was_existing' => $existingPeer !== null,
            'status_changed' => $existingPeer && $existingPeer['is_seeder'] !== $isSeeder,
        ];
    }

    /**
     * Remove a peer (on stopped event).
     */
    public function removePeer(int $torrentId, string $peerId): ?array
    {
        $redis = $this->redis();
        $peerKey = $this->prefix."peers:{$torrentId}";

        $existingPeer = $this->getPeer($torrentId, $peerId);

        if (! $existingPeer) {
            return null;
        }

        // Remove from hash
        $redis->hdel($peerKey, $peerId);

        // Update counts
        if ($existingPeer['is_seeder']) {
            $this->decrementSeeders($torrentId);
        } else {
            $this->decrementLeechers($torrentId);
        }

        // Remove from user's peer set (only if user tracking was active)
        if ($existingPeer['user_id'] !== null) {
            $redis->srem(
                $this->prefix."user:{$existingPeer['user_id']}:peers",
                "{$torrentId}:{$peerId}"
            );
        }

        // Remove from IP's peer set
        $redis->srem($this->prefix."ip:{$existingPeer['ip']}:peers", $peerId);

        return $existingPeer;
    }

    /**
     * Get a specific peer's data.
     */
    public function getPeer(int $torrentId, string $peerId): ?array
    {
        $redis = $this->redis();
        $peerKey = $this->prefix."peers:{$torrentId}";

        $data = $redis->hget($peerKey, $peerId);

        if (! $data) {
            return null;
        }

        $peer = json_decode($data, true);

        // Check if peer has expired
        if (time() - $peer['last_action'] > $this->peerExpiry) {
            $this->removePeer($torrentId, $peerId);

            return null;
        }

        return $peer;
    }

    /**
     * Get peers for announce response.
     *
     * @return array<array{ip: string, port: int, peer_id: string}>
     */
    public function getPeersForAnnounce(
        int $torrentId,
        string $excludePeerId,
        bool $isSeeder,
        int $limit,
    ): array {
        $redis = $this->redis();
        $peerKey = $this->prefix."peers:{$torrentId}";

        $allPeers = $redis->hgetall($peerKey);
        $peers = [];
        $now = time();

        foreach ($allPeers as $peerId => $data) {
            // Skip the requesting peer
            if ($peerId === $excludePeerId) {
                continue;
            }

            $peer = json_decode($data, true);

            // Skip expired peers
            if ($now - $peer['last_action'] > $this->peerExpiry) {
                continue;
            }

            // If requester is seeder, only return leechers
            if ($isSeeder && $peer['is_seeder']) {
                continue;
            }

            $peers[] = [
                'ip' => $peer['ip'],
                'port' => $peer['port'],
                'peer_id' => $peer['peer_id'],
            ];

            if (count($peers) >= $limit) {
                break;
            }
        }

        // Shuffle for fairness
        shuffle($peers);

        return $peers;
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
     * Get count of peers for a user on a specific torrent.
     */
    public function getUserPeerCountForTorrent(int $userId, int $torrentId): int
    {
        $redis = $this->redis();
        $userPeers = $redis->smembers($this->prefix."user:{$userId}:peers");

        $count = 0;
        foreach ($userPeers as $peer) {
            if (str_starts_with($peer, "{$torrentId}:")) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get count of peers from a specific IP.
     */
    public function getIpPeerCount(string $ip): int
    {
        return (int) $this->redis()->scard($this->prefix."ip:{$ip}:peers");
    }

    /**
     * Clean up expired peers (run periodically via scheduler).
     */
    public function cleanupExpiredPeers(int $torrentId): int
    {
        $redis = $this->redis();
        $peerKey = $this->prefix."peers:{$torrentId}";

        $allPeers = $redis->hgetall($peerKey);
        $now = time();
        $removed = 0;

        foreach ($allPeers as $peerId => $data) {
            $peer = json_decode($data, true);

            if ($now - $peer['last_action'] > $this->peerExpiry) {
                $this->removePeer($torrentId, $peerId);
                $removed++;
            }
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
