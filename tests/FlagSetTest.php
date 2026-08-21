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
use Zuko\BitMasks\FlagSet;
use Zuko\BitMasks\Tests\Fixtures\PivotNetwork;

class FlagSetTest extends TestCase
{
    #[Test]
    public function it_builds_a_sorted_unique_set_from_mixed_flags(): void
    {
        $set = FlagSet::from([PivotNetwork::Icloud, PivotNetwork::Gmail, 1, PivotNetwork::Gmail]);

        $this->assertSame([1, 250], $set->ids());
        $this->assertSame(2, $set->count());
        $this->assertFalse($set->isEmpty());
    }

    #[Test]
    public function none_is_empty(): void
    {
        $set = FlagSet::none(PivotNetwork::class);

        $this->assertTrue($set->isEmpty());
        $this->assertSame([], $set->ids());
        $this->assertSame([], $set->flags());
    }

    #[Test]
    public function it_supports_arbitrary_non_power_of_two_ids(): void
    {
        $set = FlagSet::from([PivotNetwork::Proton, PivotNetwork::Icloud], PivotNetwork::class);

        $this->assertSame([100, 250], $set->ids());
        $this->assertSame(['Proton', 'Icloud'], $set->names());
    }

    #[Test]
    public function has_checks_all_and_has_any_checks_one(): void
    {
        $set = FlagSet::from([PivotNetwork::Gmail, PivotNetwork::Proton]);

        $this->assertTrue($set->has(PivotNetwork::Gmail));
        $this->assertTrue($set->has(PivotNetwork::Gmail, PivotNetwork::Proton));
        $this->assertFalse($set->has(PivotNetwork::Gmail, PivotNetwork::Icloud));

        $this->assertTrue($set->hasAny(PivotNetwork::Icloud, PivotNetwork::Proton));
        $this->assertFalse($set->hasAny(PivotNetwork::Icloud));
        $this->assertTrue($set->hasNone(PivotNetwork::Icloud));
    }

    #[Test]
    public function equals_is_order_independent(): void
    {
        $set = FlagSet::from([PivotNetwork::Gmail, PivotNetwork::Proton]);

        $this->assertTrue($set->equals([PivotNetwork::Proton, PivotNetwork::Gmail]));
        $this->assertFalse($set->equals(PivotNetwork::Gmail));
    }

    #[Test]
    public function add_remove_toggle_clear_are_immutable(): void
    {
        $base = FlagSet::none(PivotNetwork::class);

        $added = $base->add(PivotNetwork::Gmail, PivotNetwork::Icloud);
        $this->assertTrue($base->isEmpty());
        $this->assertSame([1, 250], $added->ids());

        $removed = $added->remove(PivotNetwork::Gmail);
        $this->assertSame([250], $removed->ids());

        $toggled = $removed->toggle(PivotNetwork::Gmail, PivotNetwork::Icloud);
        $this->assertSame([1], $toggled->ids());

        $this->assertTrue($toggled->clear()->isEmpty());
    }

    #[Test]
    public function flags_and_names_resolve_bound_enum_cases(): void
    {
        $set = FlagSet::from([1, 100], PivotNetwork::class);

        $this->assertSame([PivotNetwork::Gmail, PivotNetwork::Proton], $set->flags());
        $this->assertSame(['Gmail', 'Proton'], $set->names());
    }

    #[Test]
    public function names_without_enum_throws(): void
    {
        $this->expectException(\LogicException::class);

        FlagSet::from([1, 2])->names();
    }

    #[Test]
    public function it_json_serializes_to_the_id_list(): void
    {
        $this->assertSame('[1,100]', json_encode(FlagSet::from([PivotNetwork::Proton, PivotNetwork::Gmail], PivotNetwork::class)));
    }

    #[Test]
    public function it_rejects_negative_ids(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FlagSet::from([-1]);
    }
}
