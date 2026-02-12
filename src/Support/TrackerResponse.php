<?php

declare(strict_types=1);

namespace Marque\Threepio\Support;

use Illuminate\Http\Response;

/**
 * Helper for building BitTorrent tracker responses.
 */
final class TrackerResponse
{
    /**
     * Create a successful announce response.
     *
     * @param array<array{ip: string, port: int, peer_id?: string}> $peers
     */
    public static function announce(
        array $peers,
        int $complete,
        int $incomplete,
        int $interval,
        int $minInterval,
        bool $compact = true,
    ): Response {
        $response = [
            'interval' => $interval,
            'min interval' => $minInterval,
            'complete' => $complete,
            'incomplete' => $incomplete,
        ];

        if ($compact) {
            $response['peers'] = self::compactPeers($peers);
        } else {
            $response['peers'] = self::dictionaryPeers($peers);
        }

        return self::bencodedResponse($response);
    }

    /**
     * Create a scrape response.
     *
     * @param array<string, array{complete: int, downloaded: int, incomplete: int}> $files
     */
    public static function scrape(array $files): Response
    {
        $bencFiles = [];

        foreach ($files as $infoHash => $stats) {
            // Convert hex info_hash back to binary for the response
            $binaryHash = pack('H*', $infoHash);
            $bencFiles[$binaryHash] = [
                'complete' => $stats['complete'],
                'downloaded' => $stats['downloaded'],
                'incomplete' => $stats['incomplete'],
            ];
        }

        return self::bencodedResponse(['files' => $bencFiles]);
    }

    /**
     * Create an error response.
     */
    public static function error(string $message): Response
    {
        return self::bencodedResponse([
            'failure reason' => $message,
        ]);
    }

    /**
     * Create a bencoded HTTP response.
     *
     * @param array<string, mixed> $data
     */
    private static function bencodedResponse(array $data): Response
    {
        $encoded = Bencode::encode($data);

        $response = new Response($encoded, 200);
        $response->header('Content-Type', 'text/plain; charset=ISO-8859-1');
        $response->header('Pragma', 'no-cache');
        $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }

    /**
     * Build compact peer string (6 bytes per peer: 4 for IP, 2 for port).
     *
     * @param array<array{ip: string, port: int}> $peers
     */
    private static function compactPeers(array $peers): string
    {
        $compact = '';

        foreach ($peers as $peer) {
            $ip = ip2long($peer['ip']);

            if ($ip === false) {
                continue; // Skip invalid IPs
            }

            $compact .= pack('Nn', $ip, $peer['port']);
        }

        return $compact;
    }

    /**
     * Build dictionary peer list (for older clients).
     *
     * @param array<array{ip: string, port: int, peer_id?: string}> $peers
     * @return array<int, array{ip: string, port: int, peer id?: string}>
     */
    private static function dictionaryPeers(array $peers): array
    {
        $result = [];

        foreach ($peers as $peer) {
            $peerDict = [
                'ip' => $peer['ip'],
                'port' => $peer['port'],
            ];

            if (isset($peer['peer_id'])) {
                $peerDict['peer id'] = $peer['peer_id'];
            }

            $result[] = $peerDict;
        }

        return $result;
    }
}
