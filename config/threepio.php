<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Threepio - BitTorrent Protocol Configuration
    |--------------------------------------------------------------------------
    |
    | Shared protocol settings used by tracker packages (bloodhound, hound).
    |
    */

    // Announce interval in seconds (default 30 minutes)
    'announce_interval' => env('THREEPIO_ANNOUNCE_INTERVAL', 1800),

    // Minimum announce interval in seconds (default 5 minutes)
    'min_announce_interval' => env('THREEPIO_MIN_ANNOUNCE_INTERVAL', 300),

    // Maximum peers to return in announce response
    'max_peers_per_announce' => env('THREEPIO_MAX_PEERS', 50),

    // Peer expiry time in seconds (default 1 hour - peers not announcing are removed)
    'peer_expiry' => env('THREEPIO_PEER_EXPIRY', 3600),

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    */

    'redis' => [
        'connection' => env('THREEPIO_REDIS_CONNECTION', 'default'),
        'prefix' => env('THREEPIO_REDIS_PREFIX', 'marque:'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Peer Response Format
    |--------------------------------------------------------------------------
    |
    | Controls the format of peer lists returned to clients.
    | 'auto' - detect from client request (compact if supported)
    | 'compact' - always use compact format (6 bytes per peer)
    | 'dictionary' - always use dictionary format (for older clients)
    |
    */

    'peer_response_format' => env('THREEPIO_PEER_FORMAT', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Port Blacklist
    |--------------------------------------------------------------------------
    |
    | Ports that are commonly used by other P2P software and should be blocked.
    |
    */

    'blacklisted_ports' => [
        411, 412, 413,      // Direct Connect
        1214,               // Kazaa
        4662,               // eMule
        6346, 6347,         // Gnutella
        6699,               // WinMX
        6881, 6882, 6883,   // Old BitTorrent default range (often blocked by ISPs)
        6884, 6885, 6886,
        6887, 6888, 6889,
    ],
];
