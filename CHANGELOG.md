# Changelog

All notable changes to `marque/threepio` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[docs/upgrading.md](../../docs/upgrading.md) for the story up to this point.

## [4.2.0] — 2026-11-01

> IPv6 peers are handed out, in `peers6` (BEP 7), and a dual-stack peer keeps an address in each family, so it reaches peers on both.

### Fixed

- **IPv6 peers were never handed out** (#11112). Compact packing used `ip2long()` and
  skipped every address it couldn't pack, so an IPv6 peer was recorded and given to nobody.
  Compact responses now carry IPv6 peers in `peers6`, 18 bytes each, beside the IPv4 ones in
  `peers`. An IPv4-mapped address (`::ffff:a.b.c.d`) goes in `peers`. `peers6` is left out
  when there are no IPv6 peers, so an IPv4-only response is unchanged.
- **A dual-stack peer was handed out in one family at a time.** libtorrent announces over
  IPv4 and IPv6 in turn under one peer ID, and the record kept only the latest address. A
  peer's record now keeps an `ipv4` and an `ipv6`, each with when it was last seen. An
  announce refreshes its own family and leaves the other alone, and a family not seen
  within `peer_expiry` is dropped. Records written before this have only `ip`, which is read
  as its own family, so nothing needs migrating.

### Added

- **`Support\PeerAddresses`**: `normalise()`, `family()`, `announced()` (vets an `ipv4=` or
  `ipv6=` value: right family, optionally public only, an endpoint's port dropped),
  `merge()`, `of()` (every address a peer can be reached at) and `connected()` (only those
  it connected from, for judging location). An announced address fills only the family
  the announce didn't arrive over, and never replaces the connecting address.
- **`PeerService::upsertPeer()` takes `ipv4` and `ipv6`**: addresses the client announced,
  already vetted by the tracker. Both are optional and default to null.
- **`getPeersForAnnounce()` returns each peer's `ipv4` and `ipv6`.**

## [4.1.0] — 2026-10-21

> A valid .torrent for your own test suite: `Testing\FakeTorrent`, private by default, with a distinct info_hash each time.

### Added

- **`Testing\FakeTorrent`**, a valid .torrent for consumer test suites (#11070). It is
  private by default and gives a distinct info_hash per `make()`. It returns the bytes, the
  info_hash as trove stores it, and an `UploadedFile`. Single-file or multi-file, named,
  sized, public, or pointed at any announce URL.

## [4.0.0] — 2026-10-20

> Peer storage made to be credited against: one read of a peer feeds anti-cheat and credit, reads never mutate, a member's baseline outlives the swarm when asked to, the per-IP count is of sessions, binary peer IDs are recorded, and announce and scrape parameters come from the raw query string.

### Changed

- **Breaking: the per-IP count is of sessions, not peer_ids.** `getIpPeerCount()` is
  replaced by `getIpSessionCount()`, which counts `ip:{ip}:sessions`, a set of
  `"{torrent}:{peer_id}"`. A client using one peer_id for every torrent counts once per
  torrent, and stopping one torrent removes only that session. Before, such a client
  counted once in total and left the count on its first `stopped`, while a client with a
  peer_id per torrent counted once per torrent. The set expires after twice `peer_expiry`
  without an announce. **After upgrading**, delete the old sets once:
  `redis-cli --scan --pattern '*marque:ip:*:peers' | xargs -r redis-cli del`, with the
  connection flags (`-h`, `-p`, `-n`) of your tracker's Redis on both calls, and your
  `threepio.redis.prefix` in place of `marque:` if you changed it.
- **Breaking: reads never mutate.** `getPeer()` answers null for an expired peer and
  leaves it in place; only `cleanupExpiredPeers()` (and `removePeer()`) take a peer out of
  the swarm.
- **A counter that goes backwards is credited as a new session.** A client restarted
  under the same peer_id reports from zero again. The delta is now what it reports,
  where it was clamped to 0 and lost everything between the restart and the announce.
- **`getUserPeerCountForTorrent()` counts from the torrent's peer hash** (live peers
  whose `user_id` matches) instead of scanning every torrent in the member's set, and
  accepts a `livePeers()` result already read this announce.
- **Records are keyed by the raw peer ID and no longer repeat it inside the JSON**, and
  the user agent is scrubbed to UTF-8. BEP 3 allows a peer ID of any 20 bytes; one that
  wasn't UTF-8 made `json_encode()` return false, so the peer was never recorded (#11058).
  An encoding failure now throws rather than storing nothing.
- **A record owned by another user is never a baseline**, from the swarm hash or a
  retained record. Overwriting it moves the peer between the two users' sets.
- **Intervals are measured on Carbon's clock**, not `time()`: the same clock a Laravel
  app stamps its records with, so a tracker's stored timestamps and threepio's
  intervals agree (and tests that travel in time move both).
- **The resolver is told the announcing user**, as a third argument, and may return
  `left` and `last_action`.

### Added

- **`PeerService::withPeerLock($torrentId, $peerId, $work)`**: runs `$work` while holding
  that peer's lock (a Redis key set if absent, expiring after 5 s, released only by its
  holder), or returns null if another holder kept it for the wait. A tracker crediting
  announces needs one peer's announces handled one at a time.
- **`Support\RawQuery`**: the announce or scrape query string as the client sent it. Read
  `info_hash` and `peer_id` from it, not from `$request->get()`. Laravel's TrimStrings
  middleware, global by default, strips NUL and whitespace bytes from the ends of every
  query value, which breaks about one binary hash in twenty, and PHP keeps only the last of
  a repeated key, which a multi-hash scrape sends. `first($key)` and `all($key)`. It reads
  `info_hash[]=` as `info_hash`.
- **`PeerService::peersOfUser($userId)`**: a member's live peers on every torrent, each
  with its record, read from `user:{id}:peers` and checked against the swarm hash in one
  pipelined round trip. A set member that no longer applies (expired, or another member's
  record under the same peer ID) is left out. Bloodhound lists a member's sessions with it.
- **`lookup($torrentId, $peerId, $userId): PeerLookup`**, one read of a peer for
  everything that judges or credits its announce. `upsertPeer(..., lookup:)` takes it,
  so anti-cheat and the credit see the same baseline and the peer is not read twice.
- **`PeerLookup::credit($uploaded, $downloaded)`**, the credit an announce earns against
  the lookup's baseline, computed without touching Redis. A tracker can record it
  durably before writing the swarm; `upsertPeer()` arrives at the same figures. It
  includes `measured_at`, the moment the interval ended, which is also the peer's new
  last announce.
- **`upsertPeer()` returns `prior_at`, `prior_left`, `interval_seconds` and
  `baseline_source`**: when the interval the announce closes began, the peer's state
  then, the interval capped at `peer_expiry`, and where the baseline came from.
- **`retainBaselinesFor($seconds)`**: the sweep keeps an expired peer's record in
  `gone:{torrent}` as a baseline for that long, used once by the peer's next announce.
  Off by default (hound).
- **`find()`**, the raw stored record, and **`livePeers()`**, every live peer on a
  torrent in one `HGETALL`; `getPeersForAnnounce()` accepts it.

## [3.3.0] — 2026-10-06

> Adds `Bencode::rawDictionary()`, the original bytes of each top-level value, so an info_hash can be computed the way clients compute it.

### Added

- **`Bencode::rawDictionary(string $data): array`** returns a bencoded dictionary's
  top-level values as their original bytes, keyed by name, validated as strictly as
  `decode()`. An info_hash is sha1 over the info dictionary exactly as the .torrent holds
  it. Decoding and re-encoding can change those bytes, so
  `sha1(Bencode::rawDictionary($torrent)['info'])` is the correct way to compute one. trove 4.4
  uses it (#10946).

## [3.2.1] — 2026-10-05

> Peer lists are a random selection, not the same peers reshuffled; the decoder rejects malformed bencode; and dead peers no longer linger in the swarm counters or the per-IP and per-user sets.

### Fixed

- **Dead peers could stay counted forever, and lock an IP out.** Each torrent's peer hash
  carried a Redis TTL. Once a torrent went quiet for twice `peer_expiry`, the whole hash
  vanished without going through `removePeer()`. The seeder/leecher counters and the
  per-IP and per-user sets kept its peers, and the hourly sweep could no longer find them.
  Enough of them and an IP reached hound's `max_per_ip`, or bloodhound's anti-cheat
  limits, and was refused for good. The hash no longer has a TTL. Peers leave only through
  `removePeer()`, driven by `cleanupExpiredPeers()`, which now also recounts the counters
  from the peers present and deletes them at zero (#10804).

  **After upgrading**, delete any per-IP and per-user sets that are already inflated. They
  rebuild from live announces within one announce interval, because membership is now
  re-asserted on every announce. The full key prefix is Laravel's `REDIS_PREFIX` (by default
  `<app-name>-database-`) followed by threepio's `THREEPIO_REDIS_PREFIX` (default `marque:`),
  and the keys live in your Redis connection's database. Pass the same `-h`/`-p`/`-a`/`-n`
  to both calls:
  `redis-cli --scan --pattern '*marque:ip:*:peers' | xargs -r redis-cli del`, and the same
  with `*marque:user:*:peers`.
- **The per-IP and per-user sets are re-asserted on every announce**, not just a peer's
  first. A cleared or lost set rebuilds from live traffic instead of undercounting, and a
  peer that changes IP now leaves its old address's count, which it never did before
  (#10804).
- **`getPeersForAnnounce()` returned the same peers every time.** It cut the list to
  `$limit` in hash order and then shuffled, so a swarm larger than the limit only ever
  offered its first N peers, in a different order. It now shuffles first (#10804).
- **`Bencode::decode()` accepted malformed input.** `i-05e` gave `-5`, `iabce` gave `0`,
  `x:` gave an empty string, overflowing integers clamped silently, and trailing bytes were
  ignored. An unterminated list or dictionary raised a PHP warning instead of an
  exception. All of these now throw `InvalidArgumentException`. Trailing whitespace after
  the value is still accepted, because downloaded `.torrent` files pick it up (#10804).

## [3.2.0] — 2026-09-04

> Lowers the PHP floor to 8.3, matching Laravel 13's own requirement.

### Changed

- **`php` constraint widened from `^8.4` to `^8.3`.** Nothing in this package
  ever required 8.4 — no property hooks, no asymmetric visibility, none of the
  8.4 array or `mb_*` functions — and Laravel 13 itself only requires `^8.3`.
  The old floor turned away working Laravel 13 apps for no technical reason.

  Lowering a floor never breaks an existing install: if you are on 8.4 you stay
  on 8.4 and nothing changes.

- Dev-only: the test suite moved from Pest 5 to Pest 4, because Pest 5 requires
  PHP 8.4 and so made the floor untestable. The suite uses only `it`/`test`/
  `expect`/`describe`/`beforeEach`, which are identical across both. No effect
  on consumers — `require-dev` is not installed downstream.

## [3.1.0] — 2026-09-03

> Adds an optional durable fallback for a peer baseline Redis has lost, and fixes an unbounded recursion that crashed the process when removing an expired peer.

### Added

- `PeerService::resolveBaselineUsing()` — an optional hook consulted only when Redis has
  no record of a peer. Threepio has no durable store and cannot depend on the package that
  does, so it exposes the seam; bloodhound fills it from the ledger, and hound leaves it
  unset and behaves exactly as before.
- `upsertPeer()` returns `prior_up`/`prior_down` (the baseline diffed against, null for a
  new peer) and `baseline_recovered`.

### Fixed

- **`PeerService::removePeer()` recursed until the process segfaulted when the
  peer being removed had expired.** It read the peer via `getPeer()`, which
  self-heals an expired peer by calling `removePeer()` — which called
  `getPeer()` again. Removal now does a raw read, since it does not care
  whether the peer had expired, only that it was there.

  Nothing exercised this until something swept expired peers:
  `cleanupExpiredPeers()` calls `removePeer()` for every expired peer, so it
  would crash on the first one it found.

## [3.0.0] — 2026-08-13

> Raises the floor to PHP 8.4 and Laravel 13, and pins real versions for inter-package constraints.

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
- Inter-package composer constraints now pin real versions instead of `@dev`.
