# Changelog

All notable changes to `marque/threepio` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

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
