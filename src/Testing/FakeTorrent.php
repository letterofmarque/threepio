<?php

declare(strict_types=1);

namespace Marque\Threepio\Testing;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Marque\Threepio\Support\Bencode;

/**
 * A valid .torrent for tests, so a fixture never has to be hand-bencoded.
 *
 * Private by default, because that is what a private tracker requires and the
 * case a consumer's suite most often needs. Every make() gets its own piece
 * hashes, so two fixtures never share an info_hash and trip a unique index;
 * one instance keeps the same bytes however often it is read.
 *
 * Immutable: each modifier returns a copy.
 *
 *     $torrent = FakeTorrent::make()->named('Big Buck Bunny');
 *     $torrent->bytes();     // the .torrent file
 *     $torrent->infoHash();  // 40 lowercase hex, as a client computes it
 *     $torrent->upload();    // an UploadedFile for a form or API test
 */
final class FakeTorrent
{
    private const PIECE_LENGTH = 16384;

    /** @var array<string, int>|null path => length, or null for a single file */
    private ?array $files = null;

    private function __construct(
        private readonly string $seed,
        private string $name = 'fake-torrent',
        private int $length = 1024,
        private bool $private = true,
        private string $announce = 'http://tracker.example/announce',
    ) {}

    public static function make(): self
    {
        return new self(random_bytes(16));
    }

    public function named(string $name): self
    {
        $copy = clone $this;
        $copy->name = $name;

        return $copy;
    }

    /**
     * The content size of a single-file torrent, in bytes.
     */
    public function size(int $bytes): self
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException('A torrent needs at least one byte of content');
        }

        $copy = clone $this;
        $copy->length = $bytes;
        $copy->files = null;

        return $copy;
    }

    /**
     * Make it a multi-file torrent. Paths use '/' between directories.
     *
     * @param  array<string, int>  $files  path => length in bytes
     */
    public function withFiles(array $files): self
    {
        if ($files === []) {
            throw new InvalidArgumentException('A multi-file torrent needs at least one file');
        }

        $copy = clone $this;
        $copy->files = $files;

        return $copy;
    }

    public function public(): self
    {
        $copy = clone $this;
        $copy->private = false;

        return $copy;
    }

    public function announce(string $url): self
    {
        $copy = clone $this;
        $copy->announce = $url;

        return $copy;
    }

    public function bytes(): string
    {
        return Bencode::encode(['announce' => $this->announce, 'info' => $this->info()]);
    }

    /**
     * sha1 over the info dictionary's bytes as the file holds them, in
     * lowercase hex: the form trove stores and looks torrents up by.
     */
    public function infoHash(): string
    {
        return sha1(Bencode::rawDictionary($this->bytes())['info']);
    }

    public function upload(?string $filename = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename ?? $this->name.'.torrent', $this->bytes());
    }

    /**
     * @return array<string, mixed>
     */
    private function info(): array
    {
        $info = ['name' => $this->name, 'piece length' => self::PIECE_LENGTH];

        if ($this->files === null) {
            $info['length'] = $this->length;
            $total = $this->length;
        } else {
            $info['files'] = array_map(
                fn (string $path, int $length): array => ['length' => $length, 'path' => explode('/', $path)],
                array_keys($this->files),
                array_values($this->files),
            );
            $total = array_sum($this->files);
        }

        $info['pieces'] = $this->pieces(max(1, intdiv($total + self::PIECE_LENGTH - 1, self::PIECE_LENGTH)));

        if ($this->private) {
            $info['private'] = 1;
        }

        return $info;
    }

    /**
     * One 20-byte hash per piece, derived from this instance's seed: stable for
     * the instance, different from every other one.
     */
    private function pieces(int $count): string
    {
        $pieces = '';

        for ($i = 0; $i < $count; $i++) {
            $pieces .= sha1($this->seed.$i, true);
        }

        return $pieces;
    }
}
