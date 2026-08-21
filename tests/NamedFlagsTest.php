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
use Zuko\BitMasks\BitMask;
use Zuko\BitMasks\FlagSet;
use Zuko\BitMasks\Tests\Fixtures\Network;
use Zuko\BitMasks\Tests\Fixtures\NetworkConstants;
use Zuko\BitMasks\Tests\Fixtures\PivotNetwork;
use Zuko\BitMasks\Tests\Fixtures\WideNetwork;
use Zuko\BitMasks\WideBitMask;
use Zuko\BitMasks\WideMaskDefinition;

/**
 * Flag NAMES (strings) as first-class flag input across the whole API,
 * whenever a flag enum is bound or provided.
 */
class NamedFlagsTest extends TestCase
{
    #[Test]
    public function bitmask_resolves_names_when_an_enum_is_provided(): void
    {
        $this->assertSame(1, BitMask::resolve('gmail', Network::class));
        $this->assertSame(3, BitMask::resolve(['gmail', 'yahoo'], Network::class));
        $this->assertSame(3, BitMask::from(['gmail', 'yahoo'], Network::class)->value());
    }

    #[Test]
    public function bitmask_still_treats_numeric_strings_as_values(): void
    {
        $this->assertSame(5, BitMask::resolve('5', Network::class));
    }

    #[Test]
    public function bitmask_rejects_names_without_an_enum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BitMask::resolve('gmail');
    }

    #[Test]
    public function bound_masks_accept_names_in_every_operation(): void
    {
        $mask = Network::mask('gmail');

        $this->assertTrue($mask->has('gmail'));
        $this->assertTrue($mask->hasAny('gmail', 'yahoo'));
        $this->assertTrue($mask->hasNone('outlook'));

        $mask = $mask->add('yahoo')->toggle('outlook')->remove('gmail');

        $this->assertSame(['Yahoo', 'Outlook'], $mask->names());
        $this->assertTrue($mask->equals(['yahoo', 'outlook']));
        $this->assertSame(2, $mask->intersect('yahoo')->value());
        $this->assertSame(4, $mask->diff('yahoo')->value());
    }

    #[Test]
    public function enum_helpers_resolve_names(): void
    {
        $this->assertSame(Network::Gmail, Network::fromName('gmail'));
        $this->assertSame(Network::Gmail, Network::tryFromName('GMAIL'));
        $this->assertNull(Network::tryFromName('telegram'));
        $this->assertSame(1, Network::valueOf('gmail'));
        $this->assertSame(3, Network::mask('gmail', 'yahoo')->value());
    }

    #[Test]
    public function wide_masks_resolve_names_to_global_indices(): void
    {
        $definition = new WideMaskDefinition('networks', ['networks_1', 'networks_2'], WideNetwork::class);

        $mask = WideBitMask::from(['gmail', 'proton'], $definition);

        $this->assertTrue($mask->has('proton'));
        $this->assertSame(['networks_1' => 1, 'networks_2' => 1], $mask->columns());
        $this->assertSame(['Gmail', 'Proton'], $mask->names());
        $this->assertTrue($mask->add('icloud')->has('icloud'));
    }

    #[Test]
    public function wide_masks_reject_names_without_an_enum(): void
    {
        $definition = new WideMaskDefinition('networks', ['networks_1', 'networks_2']);

        $this->expectException(\InvalidArgumentException::class);

        WideBitMask::from('gmail', $definition);
    }

    #[Test]
    public function flag_sets_resolve_names_to_flag_ids(): void
    {
        $set = FlagSet::from(['gmail', 'proton'], PivotNetwork::class);

        $this->assertSame([1, 100], $set->ids());
        $this->assertTrue($set->has('gmail'));
        $this->assertTrue($set->hasAny('icloud', 'proton'));
        $this->assertSame([1, 100, 250], $set->add('icloud')->ids());
        $this->assertTrue($set->remove('gmail')->equals(['proton']));
    }

    #[Test]
    public function the_bitmask_helper_accepts_names(): void
    {
        $this->assertSame(3, bitmask(['gmail', 'yahoo'], Network::class)->value());
    }

    #[Test]
    public function bitmask_value_converts_names_straight_to_integers(): void
    {
        $this->assertSame(1, bitmask_value('gmail', Network::class));
        $this->assertSame(3, bitmask_value(['gmail', 'yahoo'], Network::class));
        $this->assertSame(1, bitmask_value(Network::Gmail));
        $this->assertSame(5, bitmask_value(5));
        $this->assertSame(2, bitmask_value('yahoo mail', NetworkConstants::class));
    }
}
