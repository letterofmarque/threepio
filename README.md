# Marque Threepio

BitTorrent protocol primitives for the
[Marque](https://github.com/letterofmarque/marque) tracker platform. Bencode, tracker
responses, Redis-backed peer storage, and the shared protocol configuration.

Threepio is the layer underneath both trackers. It speaks the wire protocol and holds the
live swarm; it has no opinion about users, ratio or authentication, which is what lets
[bloodhound](../bloodhound/README.md) (private) and [hound](../hound/README.md) (public)
share it without sharing a policy.

You rarely install it directly — both tracker packages require it. Install it on its own
if you're building a tracker package of your own, or just want the Bencode codec.

> The name is the protocol droid. Fluent in over six million forms of communication;
> here, mainly one.

## Installation

```bash
composer require marque/threepio
```

Publish the config:

```bash
php artisan vendor:publish --tag=threepio-config
```

Threepio has no dependency on `marque/trove` and no migrations.

### Redis is required, and it is a real Redis

**Every deployment that serves announces needs a running Redis server.** Not "for
performance" — the peer store has no database fallback, and the announce path fatals
without a working connection.

It is genuinely Redis, not Laravel's cache pointed at Redis. `PeerService` uses Redis data
structures directly — sets for swarm and per-IP membership, a hash per peer, `incrby` for
atomic seeder/leecher counters, `expire` for peer TTL. None of that is expressible through
Laravel's cache abstraction, which offers get/put/forget on opaque values. Pointing
`CACHE_STORE` at file or database does not degrade the tracker gracefully; it fatals on the
first announce.

`illuminate/redis` is a hard requirement of this package, but it is only the Laravel layer
— it still needs a client to reach a server. Install **either**:

```bash
# the C extension (faster, and what REDIS_CLIENT defaults to)
pecl install redis

# or the pure-PHP client
composer require predis/predis
```

Both satisfy Laravel's `REDIS_CLIENT`. They are listed as `suggest` rather than `require`
precisely because either one is valid and forcing the extension would exclude working
predis installs.

**An install that never serves an announce genuinely does not need Redis** — a
catalogue-only or API-only deployment with no tracker package. If you have `bloodhound` or
`hound` installed, you need it.

#### What happens if Redis loses state

Worth knowing before you decide where to run it. Redis holds the baseline that announce
deltas are diffed against. If it restarts and comes back empty, the next announce has
nothing to compare against and credits **zero** — silently — for everything that peer
transferred across the gap.

On a public tracker that costs nothing, because nobody is credited anyway. On a private
tracker it is ratio data quietly going wrong, which is what gets people banned.
`bloodhound` closes this with the [baseline resolver](#the-baseline-resolver) wired to its
announce ledger, so the durable record supplies the baseline Redis lost. That mechanism is
the reason the ledger exists.

## What's in it

| Class | Purpose |
|---|---|
| `Support\Bencode` | Bencode encoder/decoder |
| `Support\TrackerResponse` | Builds bencoded announce, scrape and failure responses |
| `Services\PeerService` | Redis-backed live peer storage |
| `Enums\AnnounceEvent` | `started`, `completed`, `stopped` |
| `Http\Middleware\BlockBrowsers` | Rejects web browsers from tracker endpoints |

### Bencode

Static encode/decode for the four bencode types — strings, integers, lists and
dictionaries.

```php
use Marque\Threepio\Support\Bencode;

Bencode::encode(['interval' => 1800, 'complete' => 4]);
// d8:completei4e8:intervali1800ee

Bencode::decode('d8:completei4e8:intervali1800ee');
// ['complete' => 4, 'interval' => 1800]
```

Dictionary keys are sorted on encode, as the spec requires. A sequential array encodes as
a list and an associative one as a dictionary, decided by `array_is_list()`. Malformed
input throws `InvalidArgumentException` — including the easily-missed cases, like an
integer with leading zeros or `-0`, both of which are invalid bencode.

Note that decoding is lossy in one direction: bencode does not distinguish a list from a
dictionary with sequential integer keys, so a round trip can change shape. It is the
protocol's ambiguity, not this implementation's.

### TrackerResponse

Builds the three response shapes a tracker returns, each already bencoded with the right
content type and no-cache headers.

```php
use Marque\Threepio\Support\TrackerResponse;

TrackerResponse::announce(
    peers: $peers,
    complete: $seeders,
    incomplete: $leechers,
    interval: 1800,
    minInterval: 300,
    compact: true,
);

TrackerResponse::scrape($files);
TrackerResponse::error('Torrent not registered');
```

**Failures are HTTP 200.** A BitTorrent failure is a bencoded `failure reason` key in the
body, not a status code — clients expect 200 and will treat a 4xx as a transport problem
rather than showing your message. That's protocol convention, not an oversight.

Compact peers pack to 6 bytes each (4 IP, 2 port); an unparseable IP is skipped rather
than corrupting the run. Dictionary format is available for older clients. Scrape takes
hex info_hashes and converts them back to binary for the response.

### PeerService

The live swarm. Everything is in Redis with a configurable prefix, and peers expire on a
TTL rather than needing a sweep on the hot path.

| Method | Purpose |
|---|---|
| `upsertPeer(...)` | Add or update a peer; returns the peer's computed deltas |
| `removePeer($torrentId, $peerId)` | Remove a peer (a `stopped` event) |
| `getPeer($torrentId, $peerId)` | One peer's stored state, or null |
| `getPeersForAnnounce($torrentId, $excludePeerId, $isSeeder, $limit)` | Peer list for a response |
| `getSeeders($torrentId)` / `getLeechers($torrentId)` | Swarm counters |
| `getSwarmStats($torrentId)` | Totals plus both counters, for anti-cheat |
| `getUserPeerCountForTorrent($userId, $torrentId)` | Per-user connection limiting |
| `getIpPeerCount($ip)` | Per-IP connection limiting |
| `cleanupExpiredPeers($torrentId)` | Sweep expired peers, returns the count removed |

`getPeersForAnnounce()` filters by what the requester is: a seeder is handed **leechers
only**, since two seeders have nothing to exchange, while a leecher gets everyone. Expired
peers and the requester itself are skipped, and the result is shuffled so the same peers
aren't handed out in hash order every time.

Pass `userId: 0` for an anonymous peer. Hound does exactly that; the per-user keys are
simply not maintained for user `0`.

#### The baseline resolver

The one piece of `PeerService` worth understanding before you build on it.

Byte deltas are computed against the peer's previous cumulative counters, which normally
live in Redis. When Redis has no record of a peer, that is **two different situations
which look identical from inside the service**: a genuinely new peer, or a peer whose
state Redis lost. Assuming "new" in the second case silently credits zero for everything
transferred since that peer's last announce.

`resolveBaselineUsing()` lets a tracker supply a durable fallback, consulted only when
Redis comes up empty:

```php
$peerService->resolveBaselineUsing(function (int $torrentId, string $peerId): ?array {
    // Look the peer's last known totals up in your own durable record.
    // Return null when it genuinely has no history.
    return ['uploaded' => $row->uploaded, 'downloaded' => $row->downloaded];
});
```

Returning `null` is the correct answer for a first announce — a peer with no history has
no baseline, and inventing one is worse than admitting there isn't one.

Bloodhound wires this to its announce ledger, which is how a Redis restart stops costing
users their credit. Hound leaves it unset: a public tracker credits nobody, so there is
nothing to lose. If you write your own tracker package and track bytes, wire it.

### BlockBrowsers

Middleware for tracker endpoints. Rejects requests carrying `Cookie`, `Accept-Language`
or `Accept-Charset` headers, and anything whose user agent matches a known browser
pattern. BitTorrent clients send none of those.

Both tracker packages apply it to their announce and scrape routes already — you only
need it when registering endpoints of your own.

### AnnounceEvent

```php
use Marque\Threepio\Enums\AnnounceEvent;

AnnounceEvent::tryFrom($request->get('event'));  // null for a regular announce
```

Three cases — `Started`, `Completed`, `Stopped`. A regular interval announce sends no
event at all, so `tryFrom()` returning null is the normal path, not an error.

## Configuration

Published to `config/threepio.php`. These are the settings **shared** by every tracker
package; per-tracker settings live in `config/bloodhound.php` or `config/hound.php`.

### Timing

| Key | Default | Description |
|-----|---------|-------------|
| `announce_interval` | `1800` | Seconds between announces (sent to clients) |
| `min_announce_interval` | `300` | Minimum interval clients are told to respect |
| `peer_expiry` | `3600` | Seconds before an inactive peer is dropped |

```env
THREEPIO_ANNOUNCE_INTERVAL=1800
THREEPIO_MIN_ANNOUNCE_INTERVAL=300
THREEPIO_PEER_EXPIRY=3600
```

Keep `peer_expiry` comfortably above `announce_interval`. Set it below and peers expire
between their own announces, so the swarm reads as empty and clients are handed nobody to
connect to.

### Redis

| Key | Default | Description |
|-----|---------|-------------|
| `redis.connection` | `default` | Laravel Redis connection name |
| `redis.prefix` | `marque:` | Key namespace |

```env
THREEPIO_REDIS_CONNECTION=default
THREEPIO_REDIS_PREFIX=marque:
```

Keys used, all under the prefix:

```
peers:{torrent_id}                 hash of peer_id => peer data
torrent:{torrent_id}:seeders       counter
torrent:{torrent_id}:leechers      counter
user:{user_id}:peers               set of "torrent_id:peer_id"
ip:{ip}:peers                      set of peer_ids
swarm:{torrent_id}:uploaded        total bytes up
swarm:{torrent_id}:downloaded      total bytes down
```

Give the tracker its own Redis database or prefix if the instance is shared with cache or
queues — a `FLUSHDB` aimed at your cache would otherwise take the live swarm with it.

### Peer response

| Key | Default | Description |
|-----|---------|-------------|
| `max_peers_per_announce` | `50` | Ceiling on peers returned, regardless of `numwant` |
| `peer_response_format` | `auto` | `auto`, `compact`, or `dictionary` |

`auto` honours what the client asked for. Force `compact` only if you have a reason —
a handful of old clients cannot parse it.

### Port blacklist

`blacklisted_ports` blocks ports belonging to other P2P software or commonly filtered by
ISPs: Direct Connect (411–413), Kazaa (1214), eMule (4662), Gnutella (6346–6347), WinMX
(6699), and the legacy BitTorrent default range (6881–6889).

The legacy range is on the list on purpose. It's the old default, so it's the most widely
throttled, and a peer announcing from it is usually a client nobody has configured.

## Requirements

- PHP 8.3+
- Laravel 13+
- Redis

## License

MIT
