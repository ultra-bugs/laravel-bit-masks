<?php

namespace Zuko\BitMasks\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\Tests\Fixtures\WideNetwork;
use Zuko\BitMasks\WideBitMask;
use Zuko\BitMasks\WideMaskDefinition;

class WideBitMaskTest extends TestCase
{
    private function definition(): WideMaskDefinition
    {
        return new WideMaskDefinition('networks', ['networks_1', 'networks_2'], WideNetwork::class);
    }

    #[Test]
    public function it_builds_from_flags_spanning_multiple_columns(): void
    {
        $mask = WideBitMask::from([WideNetwork::Gmail, WideNetwork::Fastmail], $this->definition());

        $this->assertSame([
            'networks_1' => 1 << 0,
            'networks_2' => 1 << 1,
        ], $mask->columns());
        $this->assertFalse($mask->isEmpty());
        $this->assertSame(2, $mask->count());
    }

    #[Test]
    public function it_builds_from_raw_columns(): void
    {
        $mask = WideBitMask::fromColumns(['networks_1' => 1, 'networks_2' => '2'], $this->definition());

        $this->assertSame(['networks_1' => 1, 'networks_2' => 2], $mask->columns());
    }

    #[Test]
    public function none_is_empty(): void
    {
        $mask = WideBitMask::none($this->definition());

        $this->assertTrue($mask->isEmpty());
        $this->assertSame(0, $mask->count());
        $this->assertSame(['networks_1' => 0, 'networks_2' => 0], $mask->columns());
    }

    #[Test]
    public function has_checks_all_flags_across_columns(): void
    {
        $mask = WideBitMask::from([WideNetwork::Gmail, WideNetwork::Icloud], $this->definition());

        $this->assertTrue($mask->has(WideNetwork::Gmail));
        $this->assertTrue($mask->has(WideNetwork::Icloud));
        $this->assertTrue($mask->has(WideNetwork::Gmail, WideNetwork::Icloud));
        $this->assertFalse($mask->has(WideNetwork::Gmail, WideNetwork::Proton));
    }

    #[Test]
    public function has_any_and_has_none(): void
    {
        $mask = WideBitMask::from(WideNetwork::Proton, $this->definition());

        $this->assertTrue($mask->hasAny(WideNetwork::Gmail, WideNetwork::Proton));
        $this->assertFalse($mask->hasAny(WideNetwork::Gmail, WideNetwork::Icloud));
        $this->assertTrue($mask->hasNone(WideNetwork::Gmail, WideNetwork::Icloud));
    }

    #[Test]
    public function equals_compares_every_column(): void
    {
        $mask = WideBitMask::from([WideNetwork::Gmail, WideNetwork::Proton], $this->definition());

        $this->assertTrue($mask->equals([WideNetwork::Gmail, WideNetwork::Proton]));
        $this->assertTrue($mask->equals([WideNetwork::Proton, WideNetwork::Gmail]));
        $this->assertFalse($mask->equals(WideNetwork::Gmail));
    }

    #[Test]
    public function add_remove_toggle_and_clear_are_immutable(): void
    {
        $base = WideBitMask::none($this->definition());

        $added = $base->add(WideNetwork::Gmail, WideNetwork::Fastmail);
        $this->assertTrue($base->isEmpty(), 'original mask must be unchanged');
        $this->assertTrue($added->has(WideNetwork::Gmail, WideNetwork::Fastmail));

        $removed = $added->remove(WideNetwork::Gmail);
        $this->assertFalse($removed->has(WideNetwork::Gmail));
        $this->assertTrue($removed->has(WideNetwork::Fastmail));

        $toggled = $removed->toggle(WideNetwork::Fastmail, WideNetwork::Gmail);
        $this->assertTrue($toggled->has(WideNetwork::Gmail));
        $this->assertFalse($toggled->has(WideNetwork::Fastmail));

        $this->assertTrue($toggled->clear()->isEmpty());
    }

    #[Test]
    public function bits_returns_ascending_global_indices(): void
    {
        $mask = WideBitMask::from([WideNetwork::Gmail, WideNetwork::Hotmail, WideNetwork::Proton, WideNetwork::Icloud], $this->definition());

        $this->assertSame([0, 62, 63, 125], $mask->bits());
    }

    #[Test]
    public function flags_and_names_resolve_bound_enum_cases(): void
    {
        $mask = WideBitMask::from([WideNetwork::Gmail, WideNetwork::Proton], $this->definition());

        $this->assertSame([WideNetwork::Gmail, WideNetwork::Proton], $mask->flags());
        $this->assertSame(['Gmail', 'Proton'], $mask->names());
    }

    #[Test]
    public function names_without_enum_throws(): void
    {
        $mask = WideBitMask::from([0, 64], new WideMaskDefinition('networks', ['networks_1', 'networks_2']));

        $this->assertSame([0, 64], $mask->flags());

        $this->expectException(LogicException::class);
        $mask->names();
    }

    #[Test]
    public function it_json_serializes_to_per_column_values(): void
    {
        $mask = WideBitMask::from([WideNetwork::Gmail, WideNetwork::Fastmail], $this->definition());

        $this->assertSame('{"networks_1":1,"networks_2":2}', json_encode($mask));
    }
}
