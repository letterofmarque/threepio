<?php

declare(strict_types=1);

use Marque\Threepio\Support\PeerAddresses;

describe('normalise', function () {
    it('reads an IPv4-mapped IPv6 address as the IPv4 address it carries', function () {
        expect(PeerAddresses::normalise('::ffff:203.0.113.7'))->toBe('203.0.113.7')
            ->and(PeerAddresses::family('::ffff:203.0.113.7'))->toBe(4);
    });

    it('leaves other addresses alone', function () {
        expect(PeerAddresses::normalise('2001:db8::1'))->toBe('2001:db8::1')
            ->and(PeerAddresses::normalise('203.0.113.7'))->toBe('203.0.113.7')
            ->and(PeerAddresses::family('nonsense'))->toBeNull();
    });
});

describe('announced', function () {
    it('takes an address of the family asked for', function () {
        expect(PeerAddresses::announced('203.0.113.7', 4, true))->toBe('203.0.113.7')
            ->and(PeerAddresses::announced('2001:4860::8888', 6, true))->toBe('2001:4860::8888');
    });

    it('refuses an address of the other family', function () {
        expect(PeerAddresses::announced('2001:4860::8888', 4, false))->toBeNull()
            ->and(PeerAddresses::announced('203.0.113.7', 6, false))->toBeNull();
    });

    it('drops the port from an endpoint', function () {
        expect(PeerAddresses::announced('203.0.113.7:6881', 4, false))->toBe('203.0.113.7')
            ->and(PeerAddresses::announced('[2001:4860::8888]:6881', 6, false))->toBe('2001:4860::8888');
    });

    it('writes an IPv6 address one way, however it was sent', function () {
        expect(PeerAddresses::announced('2001:4860:0000:0000:0000:0000:0000:8888', 6, false))->toBe('2001:4860::8888');
    });

    it('refuses private and reserved addresses when only public ones will do', function () {
        expect(PeerAddresses::announced('192.168.1.10', 4, true))->toBeNull()
            ->and(PeerAddresses::announced('fd00::1', 6, true))->toBeNull()
            ->and(PeerAddresses::announced('192.168.1.10', 4, false))->toBe('192.168.1.10');
    });

    it('refuses nothing and garbage', function () {
        expect(PeerAddresses::announced(null, 4, false))->toBeNull()
            ->and(PeerAddresses::announced('', 4, false))->toBeNull()
            ->and(PeerAddresses::announced('not-an-ip', 4, false))->toBeNull();
    });
});

describe('merge', function () {
    it('keeps the other family when a dual-stack peer announces over one', function () {
        $first = PeerAddresses::merge(null, '203.0.113.7', null, null, 1000, 3600);
        $second = PeerAddresses::merge(['ip' => '203.0.113.7', 'last_action' => 1000] + $first, '2001:db8::1', null, null, 1100, 3600);

        expect($second)->toBe([
            'ipv4' => '203.0.113.7', 'ipv4_at' => 1000, 'ipv4_connected' => true,
            'ipv6' => '2001:db8::1', 'ipv6_at' => 1100, 'ipv6_connected' => true,
        ]);
    });

    it('drops a family not seen within the expiry', function () {
        $stored = ['ip' => '203.0.113.7', 'last_action' => 1000, 'ipv4' => '203.0.113.7', 'ipv4_at' => 1000, 'ipv6' => null, 'ipv6_at' => null];

        expect(PeerAddresses::merge($stored, '2001:db8::1', null, null, 5000, 3600)['ipv4'])->toBeNull();
    });

    it('takes announced addresses over stored ones', function () {
        $stored = ['ip' => '2001:db8::1', 'last_action' => 1000, 'ipv4' => '203.0.113.7', 'ipv4_at' => 1000, 'ipv6' => '2001:db8::1', 'ipv6_at' => 1000];

        expect(PeerAddresses::merge($stored, '2001:db8::1', '198.51.100.9', null, 1100, 3600)['ipv4'])->toBe('198.51.100.9');
    });

    it('reads a record written before families were kept by its ip', function () {
        $stored = ['ip' => '203.0.113.7', 'last_action' => 1000];

        expect(PeerAddresses::merge($stored, '2001:db8::1', null, null, 1100, 3600)['ipv4'])->toBe('203.0.113.7');
    });

    it('files a mapped connecting address as IPv4', function () {
        expect(PeerAddresses::merge(null, '::ffff:203.0.113.7', null, null, 1000, 3600))
            ->toMatchArray(['ipv4' => '203.0.113.7', 'ipv4_at' => 1000, 'ipv4_connected' => true, 'ipv6' => null]);
    });

    // Found by the 10.1 docs read-through: an ipv4= over an IPv4 connection
    // replaced the connecting address, so a member sharing their account
    // could claim the owner's address and be "at the same place".
    it('never lets an announced address replace the one the announce came from', function () {
        $fields = PeerAddresses::merge(null, '198.51.100.9', '203.0.113.7', null, 1000, 3600);

        expect($fields['ipv4'])->toBe('198.51.100.9')
            ->and($fields['ipv4_connected'])->toBeTrue();
    });

    it('marks an announced address as not connected from', function () {
        $fields = PeerAddresses::merge(null, '2001:db8::1', '203.0.113.7', null, 1000, 3600);

        expect($fields)->toMatchArray(['ipv4' => '203.0.113.7', 'ipv4_connected' => false, 'ipv6_connected' => true]);
    });

    it('keeps an address connected from when the client announces the same one', function () {
        $stored = ['ip' => '203.0.113.7', 'last_action' => 1000];

        expect(PeerAddresses::merge($stored, '2001:db8::1', '203.0.113.7', null, 1100, 3600)['ipv4_connected'])->toBeTrue();
    });
});

describe('connected', function () {
    it('leaves out an address the peer only announced', function () {
        $peer = ['ip' => '2001:db8::1', 'last_action' => 1000]
            + PeerAddresses::merge(null, '2001:db8::1', '203.0.113.7', null, 1000, 3600);

        expect(PeerAddresses::connected($peer, 1000, 3600))->toBe(['ipv4' => null, 'ipv6' => '2001:db8::1'])
            ->and(PeerAddresses::of($peer, 1000, 3600))->toBe(['ipv4' => '203.0.113.7', 'ipv6' => '2001:db8::1']);
    });

    it('counts an old record\'s ip as connected from', function () {
        expect(PeerAddresses::connected(['ip' => '203.0.113.7', 'last_action' => 1000], 1000, 3600))
            ->toBe(['ipv4' => '203.0.113.7', 'ipv6' => null]);
    });
});

describe('of', function () {
    it('gives an old record its ip in its own family', function () {
        expect(PeerAddresses::of(['ip' => '2001:db8::1']))->toBe(['ipv4' => null, 'ipv6' => '2001:db8::1']);
    });

    it('leaves out a family gone stale', function () {
        $peer = ['ip' => '2001:db8::1', 'last_action' => 5000, 'ipv4' => '203.0.113.7', 'ipv4_at' => 1000, 'ipv6' => '2001:db8::1', 'ipv6_at' => 5000];

        expect(PeerAddresses::of($peer, 5000, 3600))->toBe(['ipv4' => null, 'ipv6' => '2001:db8::1']);
    });
});
