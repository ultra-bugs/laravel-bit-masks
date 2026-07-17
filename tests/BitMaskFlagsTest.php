<?php

namespace Zuko\BitMasks\Tests;

use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\Tests\Fixtures\Network;

class BitMaskFlagsTest extends TestCase
{
    #[Test]
    public function it_builds_masks_from_cases(): void
    {
        $mask = Network::mask(Network::Gmail, Network::Outlook);

        $this->assertSame(0b0101, $mask->value());
        $this->assertSame(Network::class, $mask->enum());
        $this->assertSame(0, Network::none()->value());
        $this->assertSame(0b1111, Network::all()->value());
    }

    #[Test]
    public function it_resolves_cases_from_a_mask(): void
    {
        $this->assertSame([Network::Gmail, Network::Yahoo], Network::fromMask(3));
        $this->assertSame([], Network::fromMask(0));
    }

    #[Test]
    public function cases_know_their_membership(): void
    {
        $this->assertTrue(Network::Gmail->in(0b0001));
        $this->assertFalse(Network::Yahoo->in(0b0001));
        $this->assertTrue(Network::Yahoo->notIn(0b0001));
        $this->assertTrue(Network::Outlook->in(Network::mask(Network::Outlook, Network::Hotmail)));
    }
}
