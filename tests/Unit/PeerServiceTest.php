<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Marque\Threepio\Services\PeerService;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    $this->peers = app(PeerService::class);
});

function addPeer(PeerService $peers, int $torrentId, string $peerId, bool $isSeeder = true): void
{
    $peers->upsertPeer(
        torrentId: $torrentId,
        peerId: $peerId,
        userId: 1,
        ip: '10.0.0.1',
        port: 51413,
        uploaded: 0,
        downloaded: 0,
        left: $isSeeder ? 0 : 1_000,
        userAgent: 'test',
        isSeeder: $isSeeder,
    );
}

function backdatePeer(int $torrentId, string $peerId): void
{
    $redis = Redis::connection(config('threepio.redis.connection', 'default'));
    $key = config('threepio.redis.prefix', 'marque:')."peers:{$torrentId}";

    $peer = json_decode($redis->hget($key, $peerId), true);
    $peer['last_action'] = time() - 86_400;

    $redis->hset($key, $peerId, json_encode($peer));
}

test('counts seeders and leechers as peers announce', function () {
    addPeer($this->peers, 1, '-qB4210-aaaaaaaaaaaa', isSeeder: true);
    addPeer($this->peers, 1, '-qB4210-bbbbbbbbbbbb', isSeeder: false);

    expect($this->peers->getSeeders(1))->toBe(1)
        ->and($this->peers->getLeechers(1))->toBe(1);
});

test('removing a peer decrements the right counter', function () {
    addPeer($this->peers, 1, '-qB4210-aaaaaaaaaaaa', isSeeder: true);

    $this->peers->removePeer(1, '-qB4210-aaaaaaaaaaaa');

    expect($this->peers->getSeeders(1))->toBe(0);
});

// Regression: removePeer() used to read the peer via getPeer(), which deletes
// an expired peer by calling removePeer() — so removing an expired peer
// recursed until the process segfaulted. Nothing hit it until a sweep that
// removes expired peers existed.
test('removing an expired peer terminates instead of recursing', function () {
    addPeer($this->peers, 1, '-qB4210-cccccccccccc', isSeeder: true);
    backdatePeer(1, '-qB4210-cccccccccccc');

    $removed = $this->peers->removePeer(1, '-qB4210-cccccccccccc');

    expect($removed)->not->toBeNull()
        ->and($this->peers->getSeeders(1))->toBe(0);
});

test('cleanupExpiredPeers sweeps expired peers and settles the counters', function () {
    addPeer($this->peers, 1, '-qB4210-dddddddddddd', isSeeder: true);
    addPeer($this->peers, 1, '-qB4210-eeeeeeeeeeee', isSeeder: false);
    backdatePeer(1, '-qB4210-dddddddddddd');
    backdatePeer(1, '-qB4210-eeeeeeeeeeee');

    expect($this->peers->cleanupExpiredPeers(1))->toBe(2)
        ->and($this->peers->getSeeders(1))->toBe(0)
        ->and($this->peers->getLeechers(1))->toBe(0);
});

test('cleanupExpiredPeers leaves live peers alone', function () {
    addPeer($this->peers, 1, '-qB4210-ffffffffffff', isSeeder: true);

    expect($this->peers->cleanupExpiredPeers(1))->toBe(0)
        ->and($this->peers->getSeeders(1))->toBe(1);
});

// The seam bloodhound fills to recover a baseline Redis has lost (Spec #99
// CP3). Threepio itself has no durable record — it depends on nothing that
// could hold one — so it exposes the hook and stays agnostic about what
// answers it.
describe('baseline resolver', function () {
    test('is not consulted when Redis knows the peer', function () {
        addPeer($this->peers, 1, '-qB4210-aaaaaaaaaaaa');

        $called = false;
        $this->peers->resolveBaselineUsing(function () use (&$called) {
            $called = true;

            return null;
        });

        addPeer($this->peers, 1, '-qB4210-aaaaaaaaaaaa');

        expect($called)->toBeFalse();
    });

    test('recovers the delta when Redis has lost the peer', function () {
        $this->peers->resolveBaselineUsing(fn () => ['uploaded' => 1_000, 'downloaded' => 2_000]);

        $result = $this->peers->upsertPeer(
            torrentId: 1,
            peerId: '-qB4210-bbbbbbbbbbbb',
            userId: 1,
            ip: '10.0.0.1',
            port: 51413,
            uploaded: 5_000,
            downloaded: 9_000,
            left: 0,
            userAgent: 'test',
            isSeeder: true,
        );

        expect($result['upload_delta'])->toBe(4_000)
            ->and($result['download_delta'])->toBe(7_000)
            ->and($result['prior_up'])->toBe(1_000)
            ->and($result['baseline_recovered'])->toBeTrue();
    });

    test('treats a null answer as a genuinely new peer', function () {
        $this->peers->resolveBaselineUsing(fn () => null);

        $result = $this->peers->upsertPeer(
            torrentId: 1,
            peerId: '-qB4210-cccccccccccc',
            userId: 1,
            ip: '10.0.0.1',
            port: 51413,
            uploaded: 5_000,
            downloaded: 9_000,
            left: 0,
            userAgent: 'test',
            isSeeder: true,
        );

        expect($result['upload_delta'])->toBe(0)
            ->and($result['prior_up'])->toBeNull()
            ->and($result['baseline_recovered'])->toBeFalse();
    });

    // Without a resolver — hound, or any consumer that has no ledger —
    // behaviour is exactly as before.
    test('is optional, and its absence keeps the old behaviour', function () {
        $result = $this->peers->upsertPeer(
            torrentId: 1,
            peerId: '-qB4210-dddddddddddd',
            userId: 1,
            ip: '10.0.0.1',
            port: 51413,
            uploaded: 5_000,
            downloaded: 9_000,
            left: 0,
            userAgent: 'test',
            isSeeder: true,
        );

        expect($result['upload_delta'])->toBe(0)
            ->and($result['prior_up'])->toBeNull()
            ->and($result['baseline_recovered'])->toBeFalse();
    });

    // A recovered peer has a baseline but is still absent from Redis, so it
    // must count toward the swarm as a new peer.
    test('a recovered peer still increments the swarm count', function () {
        $this->peers->resolveBaselineUsing(fn () => ['uploaded' => 1_000, 'downloaded' => 0]);

        expect($this->peers->getSeeders(7))->toBe(0);

        $this->peers->upsertPeer(
            torrentId: 7,
            peerId: '-qB4210-eeeeeeeeeeee',
            userId: 1,
            ip: '10.0.0.1',
            port: 51413,
            uploaded: 5_000,
            downloaded: 0,
            left: 0,
            userAgent: 'test',
            isSeeder: true,
        );

        expect($this->peers->getSeeders(7))->toBe(1);
    });
});
