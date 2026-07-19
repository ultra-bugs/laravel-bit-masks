<?php

namespace Zuko\BitMasks\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\Tests\Fixtures\Recipient;
use Zuko\BitMasks\Tests\Fixtures\WideNetwork;
use Zuko\BitMasks\WideBitMask;

class WideBitMasksEloquentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('recipients', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->wideBitMask('networks', 2);
        });

        $this->seed('a@x.com', [WideNetwork::Gmail]);
        $this->seed('b@x.com', [WideNetwork::Gmail, WideNetwork::Proton]);
        $this->seed('c@x.com', [WideNetwork::Icloud]);
        $this->seed('d@x.com', []);
    }

    private function seed(string $email, array $flags): void
    {
        $recipient = new Recipient(['email' => $email]);
        $recipient->networks = $flags;
        $recipient->save();
    }

    #[Test]
    public function the_blueprint_macro_creates_the_storage_columns(): void
    {
        $this->assertTrue(Capsule::schema()->hasColumn('recipients', 'networks_1'));
        $this->assertTrue(Capsule::schema()->hasColumn('recipients', 'networks_2'));
    }

    #[Test]
    public function it_fans_flags_out_to_storage_columns_including_beyond_63_bits(): void
    {
        $recipient = Recipient::where('email', 'b@x.com')->first();

        // Gmail -> column 1 bit 0; Proton -> column 2 bit 0.
        $this->assertSame(1, $recipient->getRawOriginal('networks_1'));
        $this->assertSame(1, $recipient->getRawOriginal('networks_2'));

        $icloud = Recipient::where('email', 'c@x.com')->first();
        $this->assertSame(0, $icloud->getRawOriginal('networks_1'));
        $this->assertSame(1 << 62, $icloud->getRawOriginal('networks_2'));
    }

    #[Test]
    public function the_virtual_attribute_reads_back_as_a_wide_bit_mask(): void
    {
        $recipient = Recipient::where('email', 'b@x.com')->first();

        $this->assertInstanceOf(WideBitMask::class, $recipient->networks);
        $this->assertSame(['Gmail', 'Proton'], $recipient->networks->names());
        $this->assertTrue($recipient->networks->has(WideNetwork::Proton));
    }

    #[Test]
    public function instance_helpers_read_and_mutate_wide_masks(): void
    {
        $recipient = Recipient::where('email', 'a@x.com')->first();

        $this->assertTrue($recipient->hasMask('networks', WideNetwork::Gmail));
        $this->assertFalse($recipient->hasMask('networks', WideNetwork::Icloud));
        $this->assertTrue($recipient->hasAnyMask('networks', WideNetwork::Gmail, WideNetwork::Icloud));
        $this->assertTrue($recipient->missingMask('networks', WideNetwork::Icloud));

        $recipient->addMask('networks', WideNetwork::Icloud)->save();
        $this->assertTrue($recipient->fresh()->hasMask('networks', WideNetwork::Icloud));
        $this->assertSame(1 << 62, $recipient->fresh()->getRawOriginal('networks_2'));

        $recipient->removeMask('networks', WideNetwork::Gmail)->save();
        $this->assertFalse($recipient->fresh()->hasMask('networks', WideNetwork::Gmail));

        $recipient->toggleMask('networks', WideNetwork::Gmail, WideNetwork::Icloud)->save();
        $this->assertTrue($recipient->fresh()->hasMask('networks', WideNetwork::Gmail));
        $this->assertFalse($recipient->fresh()->hasMask('networks', WideNetwork::Icloud));

        $recipient->setMask('networks', WideNetwork::Proton)->save();
        $this->assertSame(['Proton'], $recipient->fresh()->networks->names());

        $recipient->clearMask('networks')->save();
        $this->assertTrue($recipient->fresh()->networks->isEmpty());
    }

    #[Test]
    public function where_mask_has_matches_rows_with_all_flags(): void
    {
        $emails = Recipient::whereMaskHas('networks', WideNetwork::Gmail)->orderBy('email')->pluck('email')->all();
        $this->assertSame(['a@x.com', 'b@x.com'], $emails);

        // A flag stored beyond bit 63 must be matched in the right column.
        $emails = Recipient::whereMaskHas('networks', WideNetwork::Icloud)->pluck('email')->all();
        $this->assertSame(['c@x.com'], $emails);

        // Flags across both columns must all be present.
        $emails = Recipient::whereMaskHas('networks', [WideNetwork::Gmail, WideNetwork::Proton])->pluck('email')->all();
        $this->assertSame(['b@x.com'], $emails);
    }

    #[Test]
    public function where_mask_has_any_matches_rows_with_at_least_one_flag_in_any_column(): void
    {
        $emails = Recipient::whereMaskHasAny('networks', [WideNetwork::Proton, WideNetwork::Icloud])
            ->orderBy('email')->pluck('email')->all();

        $this->assertSame(['b@x.com', 'c@x.com'], $emails);
    }

    #[Test]
    public function where_mask_missing_matches_rows_without_the_flags(): void
    {
        $emails = Recipient::whereMaskMissing('networks', WideNetwork::Gmail)
            ->orderBy('email')->pluck('email')->all();

        $this->assertSame(['c@x.com', 'd@x.com'], $emails);
    }

    #[Test]
    public function where_mask_equals_matches_exact_masks_across_columns(): void
    {
        $emails = Recipient::whereMaskEquals('networks', [WideNetwork::Gmail, WideNetwork::Proton])->pluck('email')->all();
        $this->assertSame(['b@x.com'], $emails);

        $emails = Recipient::whereMaskEquals('networks', WideNetwork::Gmail)->pluck('email')->all();
        $this->assertSame(['a@x.com'], $emails);

        $emails = Recipient::whereMaskEquals('networks', [])->pluck('email')->all();
        $this->assertSame(['d@x.com'], $emails);
    }

    #[Test]
    public function or_scopes_compose_with_other_conditions(): void
    {
        $emails = Recipient::whereMaskEquals('networks', [])
            ->orWhereMaskHas('networks', WideNetwork::Icloud)
            ->orderBy('email')
            ->pluck('email')
            ->all();

        $this->assertSame(['c@x.com', 'd@x.com'], $emails);
    }
}
