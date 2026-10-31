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
     * A peer's `ipv4` and `ipv6` (from PeerService) say where it can be
     * reached in each family; a peer with only `ip` is reachable there. In
     * compact form the IPv4 addresses go in `peers` and the IPv6 ones in
     * `peers6` (BEP 7), so a dual-stack peer is in both. `peers6` is left out
     * when there are none, so an IPv4-only swarm's response is unchanged.
     *
     * @param  array<array{ip: string, port: int, peer_id?: string, ipv4?: ?string, ipv6?: ?string}>  $peers
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
            $response['peers'] = self::compactPeers($peers, 4);

            $peers6 = self::compactPeers($peers, 6);

            if ($peers6 !== '') {
                $response['peers6'] = $peers6;
            }
        } else {
            $response['peers'] = self::dictionaryPeers($peers);
        }

        return self::bencodedResponse($response);
    }

    /**
     * Create a scrape response.
     *
     * @param  array<string, array{complete: int, downloaded: int, incomplete: int}>  $files
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
     * @param  array<string, mixed>  $data
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
     * Build a compact peer string for one family: 6 bytes per IPv4 peer (4
     * for the address, 2 for the port), 18 per IPv6 peer (16 and 2).
     *
     * This used to pack with ip2long() and skip what it couldn't pack, which
     * was every IPv6 address: IPv6 peers were recorded and never handed to
     * anyone (#11112).
     *
     * @param  array<array{ip: string, port: int, ipv4?: ?string, ipv6?: ?string}>  $peers
     */
    private static function compactPeers(array $peers, int $family): string
    {
        $compact = '';

        foreach ($peers as $peer) {
            $address = PeerAddresses::of($peer)["ipv{$family}"];
            $packed = $address === null ? false : @inet_pton($address);

            if ($packed === false) {
                continue;
            }

            $compact .= $packed.pack('n', $peer['port']);
        }

        return $compact;
    }

    /**
     * Build dictionary peer list (for older clients).
     *
     * @param  array<array{ip: string, port: int, peer_id?: string}>  $peers
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
