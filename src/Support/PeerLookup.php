<?php

declare(strict_types=1);

namespace Marque\Threepio\Support;

use Marque\Threepio\Enums\BaselineSource;

/**
 * One read of a peer, shared by everything that judges or credits an announce.
 *
 * Anti-cheat and the credit used to read the peer separately, so a speed check
 * could pass against one baseline while the credit was computed against
 * another. A tracker reads this once and hands the same object to both.
 *
 * Two questions live here and they are not the same question. `stored` is
 * whatever sits in the swarm hash under this peer_id, whoever owns it and
 * however old: it decides the swarm bookkeeping (counters, sets). `baseline`
 * is what this member's announce may be diffed against: only this member's
 * record, from the swarm, a retained record, or the durable fallback.
 */
final readonly class PeerLookup
{
    /**
     * @param  array<string, mixed>|null  $baseline  uploaded, downloaded, left, last_action (each may be absent from a recovered one)
     * @param  array<string, mixed>|null  $stored  the swarm hash's record for this peer_id, any owner, any age
     */
    public function __construct(
        public ?array $baseline,
        public BaselineSource $source,
        public ?array $stored,
        public int $peerExpiry,
        public int $readAt,
    ) {}

    /**
     * Is this member's peer in the swarm right now?
     *
     * Only a swarm record counts. A retained or recovered baseline belongs to a
     * session Redis no longer lists, so the peer is new to the swarm even
     * though it has counters to diff against.
     */
    public function isLive(): bool
    {
        return $this->source === BaselineSource::Swarm
            && $this->readAt - (int) ($this->baseline['last_action'] ?? 0) <= $this->peerExpiry;
    }

    /**
     * What an announce reporting these counters is credited, against this baseline.
     *
     * Pure: it reads nothing and writes nothing, so a tracker can write its
     * durable record of the credit before it touches Redis, and upsertPeer()
     * arrives at the same figures.
     *
     * `measured_at` is when the lookup was read: the end of the interval, and
     * the time upsertPeer() stamps on the record as the peer's last announce.
     * A tracker that records the credit at that moment can later check every
     * interval exactly (`measured_at - prior_at`, capped).
     *
     * @return array{upload_delta: int, download_delta: int, prior_up: ?int, prior_down: ?int, prior_at: ?int, prior_left: ?int, interval_seconds: int, measured_at: int}
     */
    public function credit(int $uploaded, int $downloaded): array
    {
        $baseline = $this->baseline;

        if ($baseline === null) {
            return [
                'upload_delta' => 0,
                'download_delta' => 0,
                'prior_up' => null,
                'prior_down' => null,
                'prior_at' => null,
                'prior_left' => null,
                'interval_seconds' => 0,
                'measured_at' => $this->readAt,
            ];
        }

        $priorAt = isset($baseline['last_action']) ? (int) $baseline['last_action'] : null;

        return [
            'upload_delta' => self::delta($uploaded, (int) $baseline['uploaded']),
            'download_delta' => self::delta($downloaded, (int) $baseline['downloaded']),
            'prior_up' => (int) $baseline['uploaded'],
            'prior_down' => (int) $baseline['downloaded'],
            'prior_at' => $priorAt,
            'prior_left' => isset($baseline['left']) ? (int) $baseline['left'] : null,
            'interval_seconds' => $priorAt !== null ? min(max(0, $this->readAt - $priorAt), $this->peerExpiry) : 0,
            'measured_at' => $this->readAt,
        ];
    }

    /**
     * Diff a cumulative counter against its baseline.
     *
     * A counter below its baseline means the client restarted under the same
     * peer_id and is counting from zero again, so what it reports is what this
     * session moved. Crediting 0 (the old clamp) lost everything between the
     * restart and this announce.
     */
    private static function delta(int $reported, int $prior): int
    {
        return $reported >= $prior ? $reported - $prior : $reported;
    }
}
