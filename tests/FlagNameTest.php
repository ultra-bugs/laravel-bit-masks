<?php

namespace Zuko\BitMasks\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\Support\FlagName;
use Zuko\BitMasks\Tests\Fixtures\Channel;
use Zuko\BitMasks\Tests\Fixtures\Color;
use Zuko\BitMasks\Tests\Fixtures\Network;
use Zuko\BitMasks\Tests\Fixtures\NetworkConstants;

class FlagNameTest extends TestCase
{
    #[Test]
    public function it_resolves_exact_case_names(): void
    {
        $this->assertSame(Network::Gmail, FlagName::resolve(Network::class, 'Gmail'));
    }

    #[Test]
    public function it_resolves_names_case_insensitively(): void
    {
        $this->assertSame(Network::Gmail, FlagName::resolve(Network::class, 'gmail'));
        $this->assertSame(Network::Gmail, FlagName::resolve(Network::class, 'GMAIL'));
    }

    #[Test]
    public function it_resolves_names_ignoring_separators(): void
    {
        $this->assertSame(Channel::YahooMail, FlagName::resolve(Channel::class, 'yahoo mail'));
        $this->assertSame(Channel::YahooMail, FlagName::resolve(Channel::class, 'yahoo-mail'));
        $this->assertSame(Channel::YahooMail, FlagName::resolve(Channel::class, 'YAHOO_MAIL'));
        $this->assertSame(Channel::PushNotification, FlagName::resolve(Channel::class, 'push_notification'));
    }

    #[Test]
    public function try_resolve_returns_null_for_unknown_names(): void
    {
        $this->assertNull(FlagName::tryResolve(Network::class, 'telegram'));
    }

    #[Test]
    public function it_throws_for_unknown_names_listing_known_flags(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Gmail');

        FlagName::resolve(Network::class, 'telegram');
    }

    #[Test]
    public function it_rejects_non_enum_classes_on_resolve(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FlagName::resolve(NetworkConstants::class, 'gmail');
    }

    #[Test]
    public function it_reads_values_from_flag_enums(): void
    {
        $this->assertSame(1, FlagName::value(Network::class, 'gmail'));
        $this->assertSame(2, FlagName::value(Channel::class, 'yahoo mail'));
    }

    #[Test]
    public function it_rejects_string_backed_enums(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FlagName::value(Color::class, 'red');
    }

    #[Test]
    public function it_reads_values_from_constants_classes(): void
    {
        $this->assertSame(1, FlagName::value(NetworkConstants::class, 'GMAIL'));
        $this->assertSame(2, FlagName::value(NetworkConstants::class, 'yahoo mail'));
        $this->assertSame(4, FlagName::value(NetworkConstants::class, 'out-look'));
    }

    #[Test]
    public function try_value_returns_null_for_unknown_names(): void
    {
        $this->assertNull(FlagName::tryValue(Network::class, 'telegram'));
        $this->assertNull(FlagName::tryValue(NetworkConstants::class, 'telegram'));
    }
}
