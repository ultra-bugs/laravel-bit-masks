<?php
/*
 *          M""""""""`M            dP
 *          Mmmmmm   .M            88
 *          MMMMP  .MMM  dP    dP  88  .dP   .d8888b.
 *          MMP  .MMMMM  88    88  88888"    88'  `88
 *          M' .MMMMMMM  88.  .88  88  `8b.  88.  .88
 *          M         M  `88888P'  dP   `YP  `88888P'
 *          MMMMMMMMMMM    -*-  Created by Zuko  -*-
 *
 *          * * * * * * * * * * * * * * * * * * * * *
 *          * -    - -   F.R.E.E.M.I.N.D   - -    - *
 *          * -  Copyright © 2026 (Z) Programing  - *
 *          *    -  -  All Rights Reserved  -  -    *
 *          * * * * * * * * * * * * * * * * * * * * *
 */

namespace Zuko\BitMasks\Tests;

use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\Tests\Fixtures\WideNetwork;
use Zuko\BitMasks\WideMaskDefinition;

class WideMaskDefinitionTest extends TestCase
{
    private function definition(): WideMaskDefinition
    {
        return new WideMaskDefinition('networks', ['networks_1', 'networks_2'], WideNetwork::class);
    }

    #[Test]
    public function it_reports_capacity_and_wideness(): void
    {
        $definition = $this->definition();

        $this->assertTrue($definition->isWide());
        $this->assertSame(126, $definition->capacity());
        $this->assertSame(['networks_1', 'networks_2'], $definition->columns());

        $single = new WideMaskDefinition('flags', ['flags_1']);
        $this->assertFalse($single->isWide());
        $this->assertSame(63, $single->capacity());
    }

    #[Test]
    public function it_routes_a_flag_index_to_column_and_bit(): void
    {
        $definition = $this->definition();

        $this->assertSame(['networks_1', 1 << 0], $definition->route(0));
        $this->assertSame(['networks_1', 1 << 62], $definition->route(62));
        $this->assertSame(['networks_2', 1 << 0], $definition->route(63));
        $this->assertSame(['networks_2', 1 << 1], $definition->route(64));
        $this->assertSame(['networks_2', 1 << 62], $definition->route(125));
    }

    #[Test]
    public function it_rejects_an_index_beyond_capacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->definition()->route(126);
    }

    #[Test]
    public function it_decomposes_flags_into_per_column_masks(): void
    {
        $contributions = $this->definition()->contributions([
            WideNetwork::Gmail,   // column 1, bit 0
            WideNetwork::Proton,  // column 2, bit 0
            WideNetwork::Icloud,  // column 2, bit 62
        ]);

        $this->assertSame([
            'networks_1' => 1 << 0,
            'networks_2' => (1 << 0) | (1 << 62),
        ], $contributions);
    }

    #[Test]
    public function empty_flags_yield_zeroed_columns(): void
    {
        $this->assertSame(
            ['networks_1' => 0, 'networks_2' => 0],
            $this->definition()->contributions(null)
        );
    }

    #[Test]
    public function it_accepts_raw_integer_indices(): void
    {
        $this->assertSame(
            ['networks_1' => 1 << 5, 'networks_2' => 1 << 3],
            $this->definition()->contributions([5, 66])
        );
    }

    #[Test]
    public function it_rejects_string_backed_enums(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->definition()->flagIndex(Fixtures\Color::Red);
    }
}
