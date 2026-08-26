<?php

declare(strict_types=1);

use Marque\Threepio\Support\Bencode;
use Marque\Threepio\Support\TrackerResponse;

describe('TrackerResponse', function () {
    describe('announce', function () {
        it('creates valid announce response with compact peers', function () {
            $peers = [
                ['ip' => '192.168.1.1', 'port' => 6881],
                ['ip' => '192.168.1.2', 'port' => 6882],
            ];

            $response = TrackerResponse::announce(
                peers: $peers,
                complete: 5,
                incomplete: 10,
                interval: 1800,
                minInterval: 300,
                compact: true,
            );

            expect($response->getStatusCode())->toBe(200);
            expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=ISO-8859-1');

            $decoded = Bencode::decode($response->getContent());

            expect($decoded['complete'])->toBe(5);
            expect($decoded['incomplete'])->toBe(10);
            expect($decoded['interval'])->toBe(1800);
            expect($decoded['min interval'])->toBe(300);
            // Compact peers = 6 bytes per peer (4 IP + 2 port)
            expect(strlen($decoded['peers']))->toBe(12);
        });

        it('creates valid announce response with dictionary peers', function () {
            $peers = [
                ['ip' => '192.168.1.1', 'port' => 6881, 'peer_id' => '-qB4500-xxxxxxxxxxxx'],
            ];

            $response = TrackerResponse::announce(
                peers: $peers,
                complete: 1,
                incomplete: 0,
                interval: 1800,
                minInterval: 300,
                compact: false,
            );

            $decoded = Bencode::decode($response->getContent());

            expect($decoded['peers'])->toBeArray();
            expect($decoded['peers'][0]['ip'])->toBe('192.168.1.1');
            expect($decoded['peers'][0]['port'])->toBe(6881);
            expect($decoded['peers'][0]['peer id'])->toBe('-qB4500-xxxxxxxxxxxx');
        });

        it('handles empty peer list', function () {
            $response = TrackerResponse::announce(
                peers: [],
                complete: 0,
                incomplete: 0,
                interval: 1800,
                minInterval: 300,
                compact: true,
            );

            $decoded = Bencode::decode($response->getContent());

            expect($decoded['peers'])->toBe('');
            expect($decoded['complete'])->toBe(0);
            expect($decoded['incomplete'])->toBe(0);
        });

        it('skips invalid IPs in compact mode', function () {
            $peers = [
                ['ip' => '192.168.1.1', 'port' => 6881],
                ['ip' => 'invalid', 'port' => 6882],
                ['ip' => '192.168.1.3', 'port' => 6883],
            ];

            $response = TrackerResponse::announce(
                peers: $peers,
                complete: 3,
                incomplete: 0,
                interval: 1800,
                minInterval: 300,
                compact: true,
            );

            $decoded = Bencode::decode($response->getContent());

            // Only 2 valid peers (6 bytes each)
            expect(strlen($decoded['peers']))->toBe(12);
        });
    });

    describe('scrape', function () {
        it('creates valid scrape response', function () {
            $files = [
                'a000000000000000000000000000000000000000' => [
                    'complete' => 5,
                    'downloaded' => 100,
                    'incomplete' => 10,
                ],
            ];

            $response = TrackerResponse::scrape($files);

            expect($response->getStatusCode())->toBe(200);

            $decoded = Bencode::decode($response->getContent());

            expect($decoded)->toHaveKey('files');
            expect($decoded['files'])->toBeArray();
        });

        it('handles multiple torrents', function () {
            $files = [
                'a000000000000000000000000000000000000000' => [
                    'complete' => 5,
                    'downloaded' => 100,
                    'incomplete' => 10,
                ],
                'b000000000000000000000000000000000000000' => [
                    'complete' => 3,
                    'downloaded' => 50,
                    'incomplete' => 7,
                ],
            ];

            $response = TrackerResponse::scrape($files);
            $decoded = Bencode::decode($response->getContent());

            expect(count($decoded['files']))->toBe(2);
        });
    });

    describe('error', function () {
        it('creates valid error response', function () {
            $response = TrackerResponse::error('Invalid announce key');

            expect($response->getStatusCode())->toBe(200);

            $decoded = Bencode::decode($response->getContent());

            expect($decoded)->toHaveKey('failure reason');
            expect($decoded['failure reason'])->toBe('Invalid announce key');
        });

        it('sets no-cache headers', function () {
            $response = TrackerResponse::error('Test error');

            expect($response->headers->get('Cache-Control'))->toContain('no-cache');
            expect($response->headers->get('Pragma'))->toBe('no-cache');
        });
    });
});
