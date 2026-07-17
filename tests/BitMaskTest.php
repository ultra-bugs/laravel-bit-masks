<?php

namespace Zuko\BitMasks\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\BitMask;
use Zuko\BitMasks\Tests\Fixtures\Color;
use Zuko\BitMasks\Tests\Fixtures\Network;

class BitMaskTest extends TestCase
{
    #[Test]
    public function it_resolves_ints_enums_masks_and_iterables(): void
    {
        $this->assertSame(0, BitMask::resolve(null));
        $this->assertSame(5, BitMask::resolve(5));
        $this->assertSame(5, BitMask::resolve('5'));
        $this->assertSame(1, BitMask::resolve(Network::Gmail));
        $this->assertSame(3, BitMask::resolve([Network::Gmail, Network::Yahoo]));
        $this->assertSame(7, BitMask::resolve([1, [2, Network::Outlook]]));
        $this->assertSame(6, BitMask::resolve(BitMask::from(6)));
    }

    #[Test]
    public function it_rejects_negative_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BitMask::resolve(-1);
    }

    #[Test]
    public function it_rejects_string_backed_enums(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BitMask::resolve(Color::Red);
    }

    #[Test]
    public function it_rejects_unresolvable_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BitMask::resolve('not-a-number');
    }

    #[Test]
    public function it_checks_flag_presence(): void
    {
        $mask = BitMask::from([Network::Gmail, Network::Outlook]);

        $this->assertTrue($mask->has(Network::Gmail));
        $this->assertTrue($mask->has(Network::Gmail, Network::Outlook));
        $this->assertFalse($mask->has(Network::Gmail, Network::Yahoo));
        $this->assertTrue($mask->hasAny(Network::Yahoo, Network::Outlook));
        $this->assertFalse($mask->hasAny(Network::Yahoo, Network::Hotmail));
        $this->assertTrue($mask->hasNone(Network::Yahoo, Network::Hotmail));
        $this->assertFalse($mask->hasNone(Network::Outlook));
    }

    #[Test]
    public function it_adds_removes_and_toggles_immutably(): void
    {
        $original = BitMask::from(Network::Gmail);
        $added = $original->add(Network::Yahoo);

        $this->assertSame(1, $original->value());
        $this->assertSame(3, $added->value());
        $this->assertSame(1, $added->remove(Network::Yahoo)->value());
        $this->assertSame(2, $added->toggle(Network::Gmail)->value());
        $this->assertSame(0, $added->clear()->value());
        $this->assertTrue($added->clear()->isEmpty());
    }

    #[Test]
    public function it_supports_set_operations(): void
    {
        $a = BitMask::from(0b0110);
        $b = BitMask::from(0b0011);

        $this->assertSame(0b0010, $a->intersect($b)->value());
        $this->assertSame(0b0111, $a->union($b)->value());
        $this->assertSame(0b0100, $a->diff($b)->value());
        $this->assertTrue($a->equals(0b0110));
        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function it_exposes_bits_values_and_count(): void
    {
        $mask = BitMask::from(0b1010);

        $this->assertSame([1, 3], $mask->bits());
        $this->assertSame([2, 8], $mask->values());
        $this->assertCount(2, $mask);
        $this->assertSame('1010', $mask->toBits());
    }

    #[Test]
    public function it_resolves_flags_and_names_when_enum_is_bound(): void
    {
        $mask = BitMask::from(0b0101, Network::class);

        $this->assertSame([Network::Gmail, Network::Outlook], $mask->flags());
        $this->assertSame(['Gmail', 'Outlook'], $mask->names());
        $this->assertSame([Network::Gmail, Network::Outlook], $mask->toArray());
    }

    #[Test]
    public function it_auto_binds_enum_from_source_flags(): void
    {
        $this->assertSame(Network::class, BitMask::from(Network::Gmail)->enum());
        $this->assertSame(Network::class, BitMask::from([Network::Gmail, 4])->enum());
        $this->assertNull(BitMask::from(5)->enum());
        $this->assertSame(Network::class, BitMask::from(5)->withEnum(Network::class)->enum());
    }

    #[Test]
    public function it_throws_when_resolving_names_without_an_enum(): void
    {
        $this->expectException(LogicException::class);

        BitMask::from(5)->names();
    }

    #[Test]
    public function it_rejects_binding_a_non_flag_enum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BitMask::from(5, Color::class);
    }

    #[Test]
    public function it_builds_none_and_all_masks(): void
    {
        $this->assertSame(0, BitMask::none()->value());
        $this->assertSame(0b1111, BitMask::all(Network::class)->value());
        $this->assertSame(['Gmail', 'Yahoo', 'Outlook', 'Hotmail'], BitMask::all(Network::class)->names());
    }

    #[Test]
    public function it_serializes_to_int_and_string(): void
    {
        $mask = BitMask::from(6);

        $this->assertSame('6', (string) $mask);
        $this->assertSame('6', json_encode($mask));
    }

    #[Test]
    public function bitmask_helper_creates_instances(): void
    {
        $this->assertSame(5, bitmask(5)->value());
        $this->assertSame(['Gmail', 'Outlook'], bitmask(5, Network::class)->names());
        $this->assertSame(3, bitmask([Network::Gmail, Network::Yahoo])->value());
    }
}
