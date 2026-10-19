<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Marque\Threepio\Support\RawQuery;

// A tracker's info_hash and peer_id are 20 raw bytes. Laravel's TrimStrings
// strips NUL and whitespace from the ends of every query value, which breaks
// any hash or ID that starts or ends with one of those bytes, and PHP keeps
// only the last of a repeated key, which a multi-hash scrape sends. The raw
// query string is touched by neither.

function rawRequest(string $query): Request
{
    return Request::create('/announce?'.$query);
}

it('keeps the bytes at both ends that trimming would strip', function () {
    foreach (["\x00", ' ', "\t", "\n", "\r", "\x0B"] as $edge) {
        $hash = $edge.str_repeat("\xAB", 18).$edge;

        expect(RawQuery::of(rawRequest('info_hash='.rawurlencode($hash)))->first('info_hash'))->toBe($hash);
    }
});

it('keeps every value of a repeated key, in order', function () {
    $a = str_repeat("\x01", 20);
    $b = str_repeat("\x02", 20);

    expect(RawQuery::of(rawRequest('info_hash='.rawurlencode($a).'&info_hash='.rawurlencode($b)))->all('info_hash'))->toBe([$a, $b]);
});

it('decodes + as a space, as form encoding does', function () {
    expect(RawQuery::of(rawRequest('peer_id=-qB4210-aaaa+bbbbbbb'))->first('peer_id'))->toBe('-qB4210-aaaa bbbbbbb');
});

it('answers null and an empty list for a key that is absent', function () {
    $query = RawQuery::of(rawRequest('port=51413'));

    expect($query->first('info_hash'))->toBeNull()
        ->and($query->all('info_hash'))->toBe([]);
});

it('reads a key sent with [] as the key itself', function () {
    $a = str_repeat("\x01", 20);
    $b = str_repeat("\x02", 20);

    expect(RawQuery::of(rawRequest('info_hash%5B%5D='.rawurlencode($a).'&info_hash[]='.rawurlencode($b)))->all('info_hash'))->toBe([$a, $b]);
});
