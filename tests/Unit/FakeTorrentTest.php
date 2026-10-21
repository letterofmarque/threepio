<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Marque\Threepio\Support\Bencode;
use Marque\Threepio\Testing\FakeTorrent;

// #11070 item 3: consumers need valid .torrent fixtures once uploads are
// checked against the private-flag rule and downloads parse the stored file.

it('builds a valid single-file torrent, private by default', function () {
    $torrent = FakeTorrent::make();

    $decoded = Bencode::decode($torrent->bytes());

    expect($decoded['info']['private'])->toBe(1)
        ->and($decoded['info']['name'])->toBeString()
        ->and($decoded['info']['length'])->toBeInt()
        ->and($decoded['info']['piece length'])->toBeInt()
        ->and(strlen($decoded['info']['pieces']) % 20)->toBe(0);
});

it('reports the info_hash a client would compute from the bytes', function () {
    $torrent = FakeTorrent::make();

    expect($torrent->infoHash())
        ->toBe(sha1(Bencode::rawDictionary($torrent->bytes())['info']))
        ->toMatch('/^[0-9a-f]{40}$/');
});

it('makes a different info_hash each time, so fixtures never collide', function () {
    expect(FakeTorrent::make()->infoHash())->not->toBe(FakeTorrent::make()->infoHash());
});

it('keeps the same bytes and hash for one instance', function () {
    $torrent = FakeTorrent::make();

    expect($torrent->bytes())->toBe($torrent->bytes())
        ->and($torrent->infoHash())->toBe($torrent->infoHash());
});

it('can be public', function () {
    $info = Bencode::decode(FakeTorrent::make()->public()->bytes())['info'];

    expect($info)->not->toHaveKey('private');
});

it('sets the name, size and announce url', function () {
    $decoded = Bencode::decode(
        FakeTorrent::make()->named('Big Buck Bunny')->size(100_000)->announce('https://t.example/announce')->bytes()
    );

    expect($decoded['info']['name'])->toBe('Big Buck Bunny')
        ->and($decoded['info']['length'])->toBe(100_000)
        ->and($decoded['announce'])->toBe('https://t.example/announce');
});

it('has one 20-byte piece hash per piece of the content', function () {
    $info = Bencode::decode(FakeTorrent::make()->size(16384 * 3 + 1)->bytes())['info'];

    expect(strlen($info['pieces']))->toBe(20 * 4);
});

it('builds a multi-file torrent', function () {
    $info = Bencode::decode(FakeTorrent::make()->withFiles(['a.txt' => 10, 'sub/b.txt' => 20])->bytes())['info'];

    expect($info)->not->toHaveKey('length')
        ->and($info['files'])->toBe([
            ['length' => 10, 'path' => ['a.txt']],
            ['length' => 20, 'path' => ['sub', 'b.txt']],
        ]);
});

it('leaves the original untouched when a modifier is applied', function () {
    $private = FakeTorrent::make();
    $public = $private->public();

    expect(Bencode::decode($private->bytes())['info'])->toHaveKey('private')
        ->and($public->infoHash())->not->toBe($private->infoHash());
});

it('refuses a size below one byte and an empty file list', function () {
    expect(fn () => FakeTorrent::make()->size(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FakeTorrent::make()->withFiles([]))->toThrow(InvalidArgumentException::class);
});

it('hands back an UploadedFile for a form or API upload', function () {
    $torrent = FakeTorrent::make()->named('upload me');
    $file = $torrent->upload();

    expect($file)->toBeInstanceOf(UploadedFile::class)
        ->and($file->getClientOriginalName())->toBe('upload me.torrent')
        ->and($file->get())->toBe($torrent->bytes());
});
