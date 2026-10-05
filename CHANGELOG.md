# Changelog

All notable changes to `marque/threepio` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[docs/upgrading.md](../../docs/upgrading.md) for the story up to this point.

## [Unreleased]

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
  re-asserted on every announce:
  `redis-cli --scan --pattern '<prefix>ip:*:peers' | xargs -r redis-cli del` (and the same for
  `user:*:peers`). The default prefix is `marque:`.
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
