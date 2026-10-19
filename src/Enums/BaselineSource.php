<?php

declare(strict_types=1);

namespace Marque\Threepio\Enums;

/**
 * Where a peer's baseline (the counters an announce is diffed against) came from.
 */
enum BaselineSource: string
{
    /** No baseline: a first announce, or one after the session closed. */
    case None = 'none';

    /** The peer's record in the swarm hash, live or expired-but-not-yet-swept. */
    case Swarm = 'swarm';

    /** A record the sweep kept after expiry, for retainBaselinesFor() seconds. */
    case Retained = 'retained';

    /** The tracker's durable record, through resolveBaselineUsing(). */
    case Recovered = 'recovered';
}
