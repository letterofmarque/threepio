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
structures directly: a hash of peers per torrent, sets for per-user and per-IP
membership, `incr`/`decr` for atomic seeder/leecher counters, `incrby` for swarm byte
totals. None of that is expressible through
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
deltas are diffed against. If it restarts and comes back empty, or evicts a peer's record,
the next announce has nothing to compare against and credits **zero** — silently — for
everything that peer transferred across the gap.

On a public tracker that costs nothing, because nobody is credited anyway. On a private
tracker it is ratio data quietly going wrong, which is what gets people banned. So
`bloodhound` requires a Redis that persists (`appendonly yes`) and never evicts
(`maxmemory-policy noeviction`), and refuses announces in production until it does. If
Redis is lost anyway, bloodhound opens a recovery window in which the
[resolver](#retained-baselines-and-the-resolver) reads a missing baseline from its
announce ledger. See
[bloodhound's README](../bloodhound/README.md#redis).

## What's in it

| Class | Purpose |
|---|---|
| `Support\Bencode` | Bencode encoder/decoder |
| `Support\TrackerResponse` | Builds bencoded announce, scrape and failure responses |
| `Services\PeerService` | Redis-backed live peer storage |
| `Support\PeerLookup` | One read of a peer: its baseline, where it came from, and the credit an announce earns against it |
| `Support\PeerAddresses` | A peer's addresses, one per family (BEP 7): vetting `ipv4=`/`ipv6=`, keeping both families of a dual-stack peer |
| `Support\RawQuery` | The announce or scrape query string as the client sent it: read `info_hash` and `peer_id` from it, never `$request->get()` |
| `Enums\AnnounceEvent` | `started`, `completed`, `stopped` |
| `Http\Middleware\BlockBrowsers` | Rejects web browsers from tracker endpoints |
| `Testing\FakeTorrent` | A valid .torrent for your tests: bytes, info_hash and an `UploadedFile` |

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
a list and an associative one as a dictionary, decided by `array_is_list()`.

Malformed input throws `InvalidArgumentException`, because tracker and upload input is
untrusted. That covers integers that aren't plain digits (`iabce`, `ie`, `i-e`), leading
zeros including `i03e`, `-0` and `i-05e`, integers that overflow 64 bits, string lengths
that aren't plain digits or have a leading zero, a string shorter than its declared length,
an unknown type byte, and an unterminated list or dictionary. So is anything after the
value except trailing whitespace, which a downloaded `.torrent` can pick up.

Note that decoding is lossy in one direction: bencode does not distinguish a list from a
dictionary with sequential integer keys, so a round trip can change shape. It is the
protocol's ambiguity, not this implementation's.

That is also why **an info_hash must never be computed from a decoded array.** Clients hash
the info dictionary's bytes as the file holds them, and a round trip can change those
bytes (key order, a dictionary turning into a list). `rawDictionary()` returns each
top-level value's original bytes, validated as strictly as `decode()`:

```php
$infoHash = sha1(Bencode::rawDictionary($torrentFile)['info']);
```

The same bytes let you rebuild a .torrent around an untouched info dictionary (a new
`announce`, say) without changing its hash.

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

Compact peers go in two strings, by family (BEP 7): IPv4 peers in `peers`, 6 bytes each
(4 address, 2 port), and IPv6 peers in `peers6`, 18 bytes each (16 and 2). A dual-stack
peer is in both, and an IPv4-mapped address (`::ffff:1.2.3.4`) counts as IPv4. `peers6` is
left out when no peer has IPv6, so an IPv4-only swarm's response is the same as before
4.2. An unparseable address is skipped rather than corrupting the run. Dictionary format
is available for older clients and lists each peer at the address it last announced
from. Scrape takes hex info_hashes and converts them back to binary for the response.

Before 4.2, compact packing skipped every IPv6 address: IPv6 peers were recorded and
handed to nobody.

### PeerService

The live swarm. Everything is in Redis with a configurable prefix.

**Reads never change anything.** A peer that stops announcing is skipped in peer lists
and per-member counts once it is older than `peer_expiry`, and `getPeer()` answers null
for it. The seeder, leecher and per-IP counts read stored counters and sets, so they
include it until the sweep.
But it stays in the hash, the seeder/leecher counters and the per-IP and per-user sets
until `cleanupExpiredPeers()` removes it. That sweep is the only way an expired peer
leaves; it also recounts the counters from the peers present. Something has to call it
periodically: bloodhound and hound ship `bloodhound:sync-swarm-counts` and
`hound:sync-swarm-counts`, which do,
and both schedule it hourly, so the scheduler is required. A `stopped` announce removes
its peer immediately through `removePeer()`.

The peer hash has no TTL, deliberately. A peer leaves only through `removePeer()`, which
keeps the counters and sets in step, and Redis drops a torrent's peer hash itself when its
last peer goes. The per-IP sessions set and the retained-baseline hash do carry TTLs,
because both are safe to lose: they self-clean when an IP or a torrent goes quiet.

| Method | Purpose |
|---|---|
| `lookup($torrentId, $peerId, $userId)` | Read a peer once: its baseline and where it came from, as a `PeerLookup` |
| `upsertPeer(..., lookup: $lookup, ipv4: $v4, ipv6: $v6)` | Add or update a peer; returns deltas, the interval and the baseline used |
| `removePeer($torrentId, $peerId)` | Remove a peer (a `stopped` event); returns the record removed |
| `find($torrentId, $peerId)` | The stored record, whatever its age or owner |
| `getPeer($torrentId, $peerId)` | A live peer's record, or null |
| `livePeers($torrentId)` | Every live peer on a torrent, keyed by peer_id (one `HGETALL`) |
| `getPeersForAnnounce($torrentId, $excludePeerId, $isSeeder, $limit, $livePeers)` | Peer list for a response |
| `getSeeders($torrentId)` / `getLeechers($torrentId)` | Swarm counters |
| `getSwarmStats($torrentId)` | Totals plus both counters, for anti-cheat |
| `getUserPeerCountForTorrent($userId, $torrentId, $livePeers)` | A member's live peers on a torrent |
| `getIpSessionCount($ip)` | Sessions (torrent, peer_id) in an IP's set; includes expired ones until the sweep |
| `cleanupExpiredPeers($torrentId)` | Sweep expired peers, returns the count removed |
| `retainBaselinesFor($seconds)` | Keep expired peers' records as baselines (private trackers) |
| `resolveBaselineUsing($resolver)` | A durable fallback for a lost baseline |
| `withPeerLock($torrentId, $peerId, $work)` | Run `$work` holding the peer's lock; null if another holder kept it (a crediting tracker handles one peer's announces one at a time) |
| `peersOfUser($userId)` | A member's live peers on every torrent, with their records (one pipelined read) |

#### One read, shared

An announce is judged (anti-cheat) and credited against a baseline: the counters the
peer last reported. `lookup()` reads it once, and the tracker passes the same
`PeerLookup` to its checks and to `upsertPeer()`. A speed check can therefore never pass
against one baseline while the credit is computed against another.

A `PeerLookup` answers two separate questions. `stored` is whatever sits in the hash
under that peer_id, whoever owns it and however old; it decides the swarm bookkeeping.
`baseline` is what *this member's* announce may be diffed against: only this member's
record (a peer_id is the client's choice, not an identity), from the swarm, a retained
record, or the resolver, in that order. `source` says which (`BaselineSource::Swarm`,
`Retained`, `Recovered`, `None`), and `isLive()` whether the peer is in the swarm now.

`upsertPeer()` returns, alongside the byte deltas:

- `prior_up` and `prior_down`: the baseline the deltas were diffed against, or null;
- `prior_at`: when the interval this announce closes began, or null;
- `prior_left`: the peer's `left` then (0 means it was seeding);
- `interval_seconds`: the interval, capped at `peer_expiry`;
- `baseline_source`, `was_existing` and `baseline_recovered`.

A counter that goes *backwards* means the client restarted under the same peer_id and
is counting from zero again, so the delta is the reported figure, not zero.

`getPeersForAnnounce()` filters by what the requester is: a seeder is handed **leechers
only**, since two seeders have nothing to exchange, while a leecher gets everyone. Expired
peers and the requester itself are skipped. The eligible peers are shuffled and then cut
at `$limit`, so a swarm larger than the limit hands out a different selection each time.

#### One address per family

A dual-stack client announces over IPv4 and IPv6 in turn under one peer ID (libtorrent
announces once per listen interface). A record with one address would hand the peer out
only in the family it last used, so each record keeps an `ipv4` and an `ipv6`, each with
when it was last seen. An announce refreshes the family it arrived over and leaves the
other alone; a family not seen within `peer_expiry` is dropped as stale. `ip` stays the
address the last announce came from.

`upsertPeer()` also takes `ipv4` and `ipv6`: addresses the client said it has (BEP 7's
`ipv4=` and `ipv6=`). Each fills only the family the announce didn't arrive over. The
address the client connected from is the one thing the tracker saw for itself, and a
claim never replaces it. Each family also records whether a connection came from it
(`ipv4_connected`, `ipv6_connected`). `PeerAddresses::of()` gives every address the peer
can be reached at. `PeerAddresses::connected()` gives only the ones it connected from:
judge location by those, because a client can announce any address. threepio doesn't decide whether to
believe them; the tracker vets them first with `PeerAddresses::announced($value, $family,
$publicOnly)`, which takes an address or an endpoint (`[2001:db8::1]:6881`, port dropped),
refuses the wrong family, and with `$publicOnly` refuses private and reserved ranges.
`getPeersForAnnounce()` returns each peer's `ipv4` and `ipv6` for `TrackerResponse` to
split. Records written before 4.2 have only `ip`, which is read as its own family, so
nothing needs migrating.

Pass `userId: null` for a peer with no account (hound does); the per-user keys are not
maintained for it. `userId: 0` is treated as a real user id.

#### Retained baselines and the resolver

When the swarm hash has no record of a peer, that is either a genuinely new peer or one
whose record is gone. Assuming "new" in the second case credits zero for everything it
transferred since its last announce. Two seams cover it, consulted in this order.

**`retainBaselinesFor($seconds)`.** The sweep moves an expired peer's record into
`gone:{torrent}` instead of deleting it, and keeps it for that long after its last
announce. A member who comes back without having sent `stopped` (client killed, machine
off, network gone) is then credited what they moved in between. The record is used once
and deleted. A `stopped` peer is never retained, because `stopped` closes the session.
Bloodhound sets this; hound does not.

**`resolveBaselineUsing()`** supplies a durable fallback, consulted only when the swarm and
the retained records both miss. It is told the announcing user, and may answer `left` and
`last_action` (a Unix timestamp) as well, which give the recovered interval its start:

```php
$peerService->resolveBaselineUsing(function (int $torrentId, string $peerId, ?int $userId): ?array {
    // Look the peer's last known totals up in your own durable record.
    // Return null when it genuinely has no history.
    return ['uploaded' => $row->uploaded, 'downloaded' => $row->downloaded,
            'left' => $row->left, 'last_action' => $row->created_at->getTimestamp()];
});
```

Returning `null` is the correct answer for a first announce: a peer with no history has
no baseline, and inventing one is worse than admitting there isn't one.

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

### FakeTorrent

A valid .torrent for your own test suite. Uploads are checked against the tracker's
private-flag rule, and downloads parse the stored file. So a fixture of
`'fake torrent content'`, or a public torrent on a tracker that requires private ones, now
fails before your test gets to what it is testing.

```php
use Marque\Threepio\Testing\FakeTorrent;

$torrent = FakeTorrent::make();       // single file, private
$torrent->bytes();                    // the .torrent file
$torrent->infoHash();                 // 40 lowercase hex, what trove stores
$torrent->upload();                   // an UploadedFile for a form or API test

FakeTorrent::make()
    ->named('Big Buck Bunny')
    ->size(700 * 1024 * 1024)
    ->withFiles(['video.mkv' => 700_000, 'subs/en.srt' => 40])   // multi-file instead
    ->public()
    ->announce('https://tracker.example/announce');
```

It is private by default, because a private tracker requires that. Each `make()` has its
own piece hashes, so two fixtures never share an info_hash. One instance gives the same
bytes every time it is read. Modifiers return a copy and leave the original unchanged.

## Configuration

Published to `config/threepio.php`. These are the settings **shared** by every tracker
package; per-tracker settings live in `config/bloodhound.php` or `config/hound.php`.

### Timing

| Key | Default | Description |
|-----|---------|-------------|
| `announce_interval` | `1800` | Seconds between announces (sent to clients) |
| `min_announce_interval` | `300` | Minimum interval clients are told to respect |
| `peer_expiry` | `3600` | Seconds before an inactive peer is dropped; also the cap on one announce's interval |

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
ip:{ip}:sessions                   set of "torrent_id:peer_id", TTL 2 x peer_expiry
gone:{torrent_id}                  hash of peer_id => retained record, TTL the retention
swarm:{torrent_id}:uploaded        total bytes up
swarm:{torrent_id}:downloaded      total bytes down
lock:{torrent_id}:{hex peer_id}    withPeerLock()'s lock, expires after 5 s
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

MIT. See [LICENSE](LICENSE).
