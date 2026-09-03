<?php

declare(strict_types=1);

use Marque\Threepio\Support\Bencode;

describe('Bencode', function () {
    describe('encode', function () {
        it('encodes integers', function () {
            expect(Bencode::encode(0))->toBe('i0e');
            expect(Bencode::encode(42))->toBe('i42e');
            expect(Bencode::encode(-42))->toBe('i-42e');
            expect(Bencode::encode(1234567890))->toBe('i1234567890e');
        });

        it('encodes strings', function () {
            expect(Bencode::encode(''))->toBe('0:');
            expect(Bencode::encode('spam'))->toBe('4:spam');
            expect(Bencode::encode('hello world'))->toBe('11:hello world');
        });

        it('encodes binary strings', function () {
            $binary = "\x00\x01\x02\x03";
            expect(Bencode::encode($binary))->toBe("4:\x00\x01\x02\x03");
        });

        it('encodes lists', function () {
            expect(Bencode::encode([]))->toBe('le');
            expect(Bencode::encode(['spam', 'eggs']))->toBe('l4:spam4:eggse');
            expect(Bencode::encode([1, 2, 3]))->toBe('li1ei2ei3ee');
            expect(Bencode::encode(['a', 1, 'b']))->toBe('l1:ai1e1:be');
        });

        it('encodes dictionaries', function () {
            expect(Bencode::encode(['a' => 1]))->toBe('d1:ai1ee');
            expect(Bencode::encode(['cow' => 'moo', 'spam' => 'eggs']))
                ->toBe('d3:cow3:moo4:spam4:eggse');
        });

        it('sorts dictionary keys', function () {
            $dict = ['z' => 1, 'a' => 2, 'm' => 3];
            expect(Bencode::encode($dict))->toBe('d1:ai2e1:mi3e1:zi1ee');
        });

        it('encodes nested structures', function () {
            $data = [
                'list' => [1, 2, 3],
                'dict' => ['a' => 'b'],
            ];
            expect(Bencode::encode($data))->toBe('d4:dictd1:a1:be4:listli1ei2ei3eee');
        });

        it('encodes tracker response format', function () {
            $response = [
                'interval' => 1800,
                'min interval' => 300,
                'complete' => 5,
                'incomplete' => 10,
                'peers' => '',
            ];
            $encoded = Bencode::encode($response);

            expect($encoded)->toContain('8:completei5e');
            expect($encoded)->toContain('10:incompletei10e');
            expect($encoded)->toContain('8:intervali1800e');
        });

        it('throws on unsupported types', function () {
            Bencode::encode(3.14);
        })->throws(InvalidArgumentException::class);

        it('throws on objects', function () {
            Bencode::encode(new stdClass);
        })->throws(InvalidArgumentException::class);
    });

    describe('decode', function () {
        it('decodes integers', function () {
            expect(Bencode::decode('i0e'))->toBe(0);
            expect(Bencode::decode('i42e'))->toBe(42);
            expect(Bencode::decode('i-42e'))->toBe(-42);
        });

        it('decodes strings', function () {
            expect(Bencode::decode('0:'))->toBe('');
            expect(Bencode::decode('4:spam'))->toBe('spam');
            expect(Bencode::decode('11:hello world'))->toBe('hello world');
        });

        it('decodes binary strings', function () {
            $decoded = Bencode::decode("4:\x00\x01\x02\x03");
            expect($decoded)->toBe("\x00\x01\x02\x03");
        });

        it('decodes lists', function () {
            expect(Bencode::decode('le'))->toBe([]);
            expect(Bencode::decode('l4:spam4:eggse'))->toBe(['spam', 'eggs']);
            expect(Bencode::decode('li1ei2ei3ee'))->toBe([1, 2, 3]);
        });

        it('decodes dictionaries', function () {
            expect(Bencode::decode('d1:ai1ee'))->toBe(['a' => 1]);
            expect(Bencode::decode('d3:cow3:moo4:spam4:eggse'))
                ->toBe(['cow' => 'moo', 'spam' => 'eggs']);
        });

        it('decodes nested structures', function () {
            $decoded = Bencode::decode('d4:dictd1:a1:be4:listli1ei2ei3eee');
            expect($decoded)->toBe([
                'dict' => ['a' => 'b'],
                'list' => [1, 2, 3],
            ]);
        });

        it('roundtrips simple data correctly', function () {
            // Note: encode() sorts dict keys, so decoded result will be in sorted order
            $data = ['a' => 1, 'b' => 2, 'c' => [1, 2, 3]];

            $encoded = Bencode::encode($data);
            $decoded = Bencode::decode($encoded);

            expect($decoded)->toBe($data);
        });

        it('roundtrips tracker announce data', function () {
            // Keys are already sorted for this test
            $data = [
                'complete' => 5,
                'incomplete' => 10,
                'interval' => 1800,
                'peers' => '',
            ];

            $encoded = Bencode::encode($data);
            $decoded = Bencode::decode($encoded);

            expect($decoded)->toBe($data);
        });

        it('throws on invalid data', function () {
            Bencode::decode('invalid');
        })->throws(InvalidArgumentException::class);

        it('throws on truncated integer', function () {
            Bencode::decode('i42');
        })->throws(InvalidArgumentException::class);

        it('throws on truncated string', function () {
            Bencode::decode('10:short');
        })->throws(InvalidArgumentException::class);
    });
});
