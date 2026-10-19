<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Marque\Threepio\Enums\BaselineSource;
use Marque\Threepio\Services\PeerService;
use Marque\Threepio\Support\PeerLookup;

// threepio 4.0.0 (bloodhound 8.0.0 design, sections 2.1, 2.2 and 2.6): one read
// of a peer feeds both anti-cheat and credit, reads never mutate, a baseline
// outlives the swarm for a configurable while, and the per-IP count is of
// sessions rather than peer_ids.

beforeEach(function () {
    $this->redis = Redis::connection(config('threepio.redis.connection', 'default'));
    $this->redis->flushdb();
    $this->prefix = config('threepio.redis.prefix', 'marque:');
    $this->peers = app(PeerService::class);

    // Intervals are asserted to the second: a second ticking between two
    // announces would read as one more.
    $this->freezeTime();
});

function announce(
    PeerService $peers,
    int $torrentId = 1,
    string $peerId = '-qB4210-aaaaaaaaaaaa',
    ?int $userId = 1,
    string $ip = '10.0.0.1',
    int $uploaded = 0,
    int $downloaded = 0,
    int $left = 0,
    ?PeerLookup $lookup = null,
): array {
    return $peers->upsertPeer(
        torrentId: $torrentId,
        peerId: $peerId,
        userId: $userId,
        ip: $ip,
        port: 51413,
        uploaded: $uploaded,
        downloaded: $downloaded,
        left: $left,
        userAgent: 'test',
        isSeeder: $left === 0,
        lookup: $lookup,
    );
}

function rewindPeer(string $prefix, int $torrentId, string $peerId, int $seconds, string $hash = 'peers'): void
{
    $redis = Redis::connection(config('threepio.redis.connection', 'default'));
    $key = $prefix."{$hash}:{$torrentId}";

    $peer = json_decode($redis->hget($key, $peerId), true);
    $peer['last_action'] -= $seconds;

    $redis->hset($key, $peerId, json_encode($peer));
}

describe('reads never mutate', function () {
    test('getPeer() of an expired peer answers null and leaves it in place', function () {
        announce($this->peers);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 7_200);

        expect($this->peers->getPeer(1, '-qB4210-aaaaaaaaaaaa'))->toBeNull()
            ->and($this->redis->hexists($this->prefix.'peers:1', '-qB4210-aaaaaaaaaaaa'))->toBeTrue()
            ->and($this->peers->getSeeders(1))->toBe(1);
    });

    test('find() returns the stored record whatever its age', function () {
        announce($this->peers, uploaded: 500);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 7_200);

        expect($this->peers->find(1, '-qB4210-aaaaaaaaaaaa'))->toMatchArray(['uploaded' => 500, 'user_id' => 1]);
    });
});

describe('the lookup and what upsertPeer returns', function () {
    test('a regular announce returns the interval and the baseline it started from', function () {
        announce($this->peers, uploaded: 1_000, left: 0);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 1_800);
        $priorAt = $this->peers->find(1, '-qB4210-aaaaaaaaaaaa')['last_action'];

        $result = announce($this->peers, uploaded: 3_000, left: 0);

        expect($result)->toMatchArray([
            'upload_delta' => 2_000,
            'prior_up' => 1_000,
            'prior_at' => $priorAt,
            'prior_left' => 0,
            'interval_seconds' => 1_800,
            'baseline_source' => 'swarm',
        ]);
    });

    test('the interval is capped at peer_expiry', function () {
        announce($this->peers);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 50_000);

        expect(announce($this->peers)['interval_seconds'])->toBe(3_600);
    });

    test('a first announce has no baseline, no interval and null priors', function () {
        expect(announce($this->peers, uploaded: 9_000))->toMatchArray([
            'upload_delta' => 0,
            'prior_up' => null,
            'prior_at' => null,
            'prior_left' => null,
            'interval_seconds' => 0,
            'baseline_source' => 'none',
        ]);
    });

    // Design addendum A2: a client restarted under the same peer_id reports its
    // counters from zero again. What it reports is what this session moved.
    test('counters that go backwards credit the reported figure as a new session', function () {
        announce($this->peers, uploaded: 5_000, downloaded: 4_000, left: 0);

        $result = announce($this->peers, uploaded: 300, downloaded: 4_500, left: 0);

        expect($result['upload_delta'])->toBe(300)
            ->and($result['download_delta'])->toBe(500)
            ->and($result['prior_up'])->toBe(5_000);
    });

    test('upsertPeer uses the lookup it is handed rather than reading again', function () {
        announce($this->peers, uploaded: 1_000);

        $lookup = $this->peers->lookup(1, '-qB4210-aaaaaaaaaaaa', 1);

        // Redis changes between the read and the write; the credit must be the
        // one computed against what the caller (anti-cheat) saw.
        $this->redis->hset($this->prefix.'peers:1', '-qB4210-aaaaaaaaaaaa', json_encode(
            array_merge($this->peers->find(1, '-qB4210-aaaaaaaaaaaa'), ['uploaded' => 2_500])
        ));

        expect(announce($this->peers, uploaded: 3_000, lookup: $lookup)['upload_delta'])->toBe(2_000);
    });

    // F15: a peer_id is the client's choice, not an identity. Another member's
    // counters are not this member's baseline.
    test('another user\'s record is not a baseline, and overwriting it moves the user set', function () {
        announce($this->peers, userId: 1, uploaded: 8_000);

        $result = announce($this->peers, userId: 2, uploaded: 9_000);

        expect($result['upload_delta'])->toBe(0)
            ->and($result['prior_up'])->toBeNull()
            ->and($this->redis->sismember($this->prefix.'user:1:peers', '1:-qB4210-aaaaaaaaaaaa'))->toBeFalse()
            ->and($this->redis->sismember($this->prefix.'user:2:peers', '1:-qB4210-aaaaaaaaaaaa'))->toBeTrue()
            ->and($this->peers->getSeeders(1))->toBe(1);
    });

    test('lookup() reports a live record, an expired one, and none', function () {
        announce($this->peers);

        $live = $this->peers->lookup(1, '-qB4210-aaaaaaaaaaaa', 1);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 7_200);
        $expired = $this->peers->lookup(1, '-qB4210-aaaaaaaaaaaa', 1);
        $none = $this->peers->lookup(1, '-qB4210-bbbbbbbbbbbb', 1);

        expect($live->source)->toBe(BaselineSource::Swarm)
            ->and($live->isLive())->toBeTrue()
            ->and($expired->source)->toBe(BaselineSource::Swarm)
            ->and($expired->isLive())->toBeFalse()
            ->and($expired->baseline)->not->toBeNull()
            ->and($none->source)->toBe(BaselineSource::None)
            ->and($none->baseline)->toBeNull()
            ->and($none->isLive())->toBeFalse();
    });
});

describe('IP sessions (F17)', function () {
    test('one peer_id on two torrents is two sessions, and stopping one leaves the other', function () {
        announce($this->peers, torrentId: 1);
        announce($this->peers, torrentId: 2);

        expect($this->peers->getIpSessionCount('10.0.0.1'))->toBe(2);

        $this->peers->removePeer(1, '-qB4210-aaaaaaaaaaaa');

        expect($this->peers->getIpSessionCount('10.0.0.1'))->toBe(1);
    });

    test('the sessions set expires if the IP goes quiet', function () {
        announce($this->peers);

        $ttl = $this->redis->ttl($this->prefix.'ip:10.0.0.1:sessions');

        expect($ttl)->toBeGreaterThan(3_600)->toBeLessThanOrEqual(7_200);
    });

    test('a peer that changes IP leaves its old IP\'s sessions', function () {
        announce($this->peers, ip: '10.0.0.1');
        announce($this->peers, ip: '10.0.0.2');

        expect($this->peers->getIpSessionCount('10.0.0.1'))->toBe(0)
            ->and($this->peers->getIpSessionCount('10.0.0.2'))->toBe(1);
    });

    test('the old per-peer_id IP set is no longer written', function () {
        announce($this->peers);

        expect($this->redis->exists($this->prefix.'ip:10.0.0.1:peers'))->toBe(0);
    });
});

describe('live peers of a member on a torrent', function () {
    test('are counted from the torrent\'s peer hash, not the member\'s whole peer set', function () {
        announce($this->peers, peerId: '-qB4210-aaaaaaaaaaaa');
        announce($this->peers, peerId: '-qB4210-bbbbbbbbbbbb');
        announce($this->peers, peerId: '-qB4210-cccccccccccc', userId: 2);
        announce($this->peers, torrentId: 2, peerId: '-qB4210-dddddddddddd');
        rewindPeer($this->prefix, 1, '-qB4210-bbbbbbbbbbbb', 7_200);

        // Clearing the member's set proves the count no longer depends on it.
        $this->redis->del($this->prefix.'user:1:peers');

        expect($this->peers->getUserPeerCountForTorrent(1, 1))->toBe(1);
    });

    test('livePeers() returns the decoded live records keyed by peer_id', function () {
        announce($this->peers, peerId: '-qB4210-aaaaaaaaaaaa', uploaded: 10);
        announce($this->peers, peerId: '-qB4210-bbbbbbbbbbbb');
        rewindPeer($this->prefix, 1, '-qB4210-bbbbbbbbbbbb', 7_200);

        $live = $this->peers->livePeers(1);

        expect(array_keys($live))->toBe(['-qB4210-aaaaaaaaaaaa'])
            ->and($live['-qB4210-aaaaaaaaaaaa']['uploaded'])->toBe(10);
    });
});

describe('baseline retention', function () {
    test('with retention, the sweep keeps an expired record as a baseline and the next announce credits from it', function () {
        $this->peers->retainBaselinesFor(7 * 86_400);
        announce($this->peers, uploaded: 1_000);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 7_200);

        $this->peers->cleanupExpiredPeers(1);

        expect($this->redis->hexists($this->prefix.'peers:1', '-qB4210-aaaaaaaaaaaa'))->toBeFalse()
            ->and($this->peers->getSeeders(1))->toBe(0)
            ->and($this->redis->hexists($this->prefix.'gone:1', '-qB4210-aaaaaaaaaaaa'))->toBeTrue()
            ->and($this->redis->ttl($this->prefix.'gone:1'))->toBeGreaterThan(0);

        $result = announce($this->peers, uploaded: 4_000);

        expect($result['upload_delta'])->toBe(3_000)
            ->and($result['baseline_source'])->toBe('retained')
            ->and($result['interval_seconds'])->toBe(3_600)
            ->and($result['was_existing'])->toBeFalse()
            ->and($this->peers->getSeeders(1))->toBe(1)
            ->and($this->redis->hexists($this->prefix.'gone:1', '-qB4210-aaaaaaaaaaaa'))->toBeFalse();
    });

    test('without retention (hound), the sweep deletes and leaves no gone hash', function () {
        announce($this->peers, uploaded: 1_000);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 7_200);

        $this->peers->cleanupExpiredPeers(1);

        expect($this->redis->exists($this->prefix.'gone:1'))->toBe(0)
            ->and(announce($this->peers, uploaded: 4_000)['upload_delta'])->toBe(0);
    });

    test('a retained baseline older than the retention is purged and not used', function () {
        $this->peers->retainBaselinesFor(86_400);
        announce($this->peers, uploaded: 1_000);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 7_200);
        $this->peers->cleanupExpiredPeers(1);

        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 86_400, hash: 'gone');
        expect($this->peers->lookup(1, '-qB4210-aaaaaaaaaaaa', 1)->baseline)->toBeNull();

        $this->peers->cleanupExpiredPeers(1);
        expect($this->redis->hexists($this->prefix.'gone:1', '-qB4210-aaaaaaaaaaaa'))->toBeFalse();
    });

    test('a stopped peer is not retained: stopped closes the session', function () {
        $this->peers->retainBaselinesFor(7 * 86_400);
        announce($this->peers, uploaded: 1_000);

        $this->peers->removePeer(1, '-qB4210-aaaaaaaaaaaa');

        expect($this->redis->exists($this->prefix.'gone:1'))->toBe(0)
            ->and(announce($this->peers, uploaded: 4_000)['upload_delta'])->toBe(0);
    });

    test('another user\'s retained record is not a baseline', function () {
        $this->peers->retainBaselinesFor(7 * 86_400);
        announce($this->peers, userId: 1, uploaded: 1_000);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 7_200);
        $this->peers->cleanupExpiredPeers(1);

        expect(announce($this->peers, userId: 2, uploaded: 4_000)['upload_delta'])->toBe(0);
    });
});

describe('the resolver, after retention', function () {
    test('is consulted only when the swarm and the retained baselines both miss, and is told the user', function () {
        $calls = [];
        $this->peers->resolveBaselineUsing(function (int $torrentId, string $peerId, ?int $userId) use (&$calls) {
            $calls[] = [$torrentId, $peerId, $userId];

            return ['uploaded' => 1_000, 'downloaded' => 0, 'left' => 0, 'last_action' => now()->getTimestamp() - 900];
        });

        $result = announce($this->peers, userId: 5, uploaded: 1_600);

        expect($calls)->toBe([[1, '-qB4210-aaaaaaaaaaaa', 5]])
            ->and($result['upload_delta'])->toBe(600)
            ->and($result['baseline_source'])->toBe('recovered')
            ->and($result['baseline_recovered'])->toBeTrue()
            ->and($result['interval_seconds'])->toBe(900)
            ->and($result['prior_left'])->toBe(0);

        $this->peers->retainBaselinesFor(86_400);
        rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 7_200);
        $this->peers->cleanupExpiredPeers(1);
        announce($this->peers, userId: 5, uploaded: 2_000);

        expect($calls)->toHaveCount(1);
    });
});

// From the independent check: a stop credited against a retained baseline
// left it in place, so every later stop or restart credited the same
// interval again.
test('removing a peer consumes its retained baseline too', function () {
    $this->peers->retainBaselinesFor(7 * 86_400);
    announce($this->peers, uploaded: 1_000);
    rewindPeer($this->prefix, 1, '-qB4210-aaaaaaaaaaaa', 7_200);
    $this->peers->cleanupExpiredPeers(1);

    $this->peers->removePeer(1, '-qB4210-aaaaaaaaaaaa');

    expect($this->redis->hexists($this->prefix.'gone:1', '-qB4210-aaaaaaaaaaaa'))->toBeFalse()
        ->and($this->peers->lookup(1, '-qB4210-aaaaaaaaaaaa', 1)->baseline)->toBeNull();
});

// #11058: BEP 3 allows a peer ID of any 20 bytes. With invalid UTF-8 in it (or
// in the user agent) the record JSON-encoded to false, so the peer never had
// a baseline and was never credited.
test('a binary peer ID and user agent are stored, credited and listed', function () {
    $peerId = "-lt0D80-\x9a\xb3\xff\x00\x01abcdefg";

    $this->peers->upsertPeer(torrentId: 1, peerId: $peerId, userId: 1, ip: '10.0.0.1', port: 51413,
        uploaded: 1_000, downloaded: 0, left: 0, userAgent: "rtorrent/\xff\xfe", isSeeder: true);

    $result = $this->peers->upsertPeer(torrentId: 1, peerId: $peerId, userId: 1, ip: '10.0.0.1', port: 51413,
        uploaded: 4_000, downloaded: 0, left: 0, userAgent: "rtorrent/\xff\xfe", isSeeder: true);

    expect($result['upload_delta'])->toBe(3_000)
        ->and($this->peers->find(1, $peerId))->not->toBeNull()
        ->and(array_keys($this->peers->livePeers(1)))->toBe([$peerId])
        ->and($this->peers->getPeersForAnnounce(1, 'someone-else', false, 10)[0]['peer_id'])->toBe($peerId);
});

test('a peer lock lets one holder in at a time, and is released after', function () {
    $inner = null;

    $outer = $this->peers->withPeerLock(1, 'peer', function () use (&$inner) {
        $inner = $this->peers->withPeerLock(1, 'peer', fn () => 'inner ran', waitMilliseconds: 50);

        return 'outer ran';
    });

    expect($outer)->toBe('outer ran')
        ->and($inner)->toBeNull()
        ->and($this->peers->withPeerLock(1, 'peer', fn () => 'after'))->toBe('after');
});
