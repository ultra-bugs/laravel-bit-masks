<?php

namespace Zuko\BitMasks\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\Support\BitMaskClassBuilder;

class BitMaskClassBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_an_int_backed_enum(): void
    {
        $source = (new BitMaskClassBuilder(
            class: 'network flags',
            namespace: 'App\\BitMasks',
            type: BitMaskClassBuilder::TYPE_ENUM,
            flags: ['gmail', 'yahoo mail', 'out-look'],
        ))->build();

        $this->assertStringContainsString('namespace App\\BitMasks;', $source);
        $this->assertStringContainsString('enum NetworkFlags: int', $source);
        $this->assertStringContainsString('use BitMaskFlags;', $source);
        $this->assertStringContainsString('case Gmail = 1 << 0;', $source);
        $this->assertStringContainsString('case YahooMail = 1 << 1;', $source);
        $this->assertStringContainsString('case OutLook = 1 << 2;', $source);
    }

    #[Test]
    public function it_builds_a_constants_class(): void
    {
        $source = (new BitMaskClassBuilder(
            class: 'NetworkFlags',
            namespace: 'App\\BitMasks',
            type: BitMaskClassBuilder::TYPE_CONSTANTS,
            flags: ['gmail', 'yahoo mail'],
            startBit: 4,
        ))->build();

        $this->assertStringContainsString('final class NetworkFlags', $source);
        $this->assertStringContainsString('public const GMAIL = 1 << 4;', $source);
        $this->assertStringContainsString('public const YAHOO_MAIL = 1 << 5;', $source);
    }

    #[Test]
    public function generated_enum_source_is_valid_php(): void
    {
        $source = (new BitMaskClassBuilder(
            class: 'GeneratedNetworkFlags',
            namespace: 'Zuko\\BitMasks\\Tests\\Generated',
            type: BitMaskClassBuilder::TYPE_ENUM,
            flags: ['gmail', '7 up', 'Việt Nam'],
        ))->build();

        eval(substr($source, strlen('<?php')));

        $enum = 'Zuko\\BitMasks\\Tests\\Generated\\GeneratedNetworkFlags';

        $this->assertTrue(enum_exists($enum));
        $this->assertSame(1, $enum::Gmail->value);
        $this->assertSame(2, $enum::_7Up->value);
        $this->assertSame(4, $enum::ViTNam->value);
        $this->assertSame(7, $enum::all()->value());
    }

    #[Test]
    public function it_rejects_flag_counts_exceeding_the_bit_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed bit 62/');

        (new BitMaskClassBuilder(
            class: 'TooMany',
            flags: array_map(static fn (int $i) => 'flag' . $i, range(1, 64)),
        ))->build();
    }

    #[Test]
    public function it_rejects_duplicate_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/collides/');

        (new BitMaskClassBuilder(class: 'Dupes', flags: ['gmail', 'g-mail']))->build();
    }

    #[Test]
    public function it_rejects_invalid_types_and_empty_flag_lists(): void
    {
        try {
            (new BitMaskClassBuilder(class: 'EmptyFlags'))->build();
            $this->fail('Expected exception for empty flag list.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('At least one flag', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown type/');

        (new BitMaskClassBuilder(class: 'BadType', type: 'interface', flags: ['a']))->build();
    }
}
