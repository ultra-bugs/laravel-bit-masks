<?php

namespace Zuko\BitMasks\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\BitMask;
use Zuko\BitMasks\Casts\AsBitMask;
use Zuko\BitMasks\Tests\Fixtures\Network;
use Zuko\BitMasks\Tests\Fixtures\Subscriber;

class HasBitMasksTest extends TestCase
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

        Capsule::schema()->create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->bitMask('networks');
            $table->bitMask('toggles');
        });

        Subscriber::insert([
            ['email' => 'a@x.com', 'networks' => BitMask::resolve(Network::Gmail), 'toggles' => 0],
            ['email' => 'b@x.com', 'networks' => BitMask::resolve([Network::Gmail, Network::Yahoo]), 'toggles' => 1],
            ['email' => 'c@x.com', 'networks' => BitMask::resolve(Network::Outlook), 'toggles' => 2],
            ['email' => 'd@x.com', 'networks' => 0, 'toggles' => 3],
        ]);
    }

    #[Test]
    public function it_casts_mask_columns_to_bitmask_instances(): void
    {
        $subscriber = Subscriber::where('email', 'b@x.com')->first();

        $this->assertInstanceOf(BitMask::class, $subscriber->networks);
        $this->assertSame(3, $subscriber->networks->value());
        $this->assertSame(Network::class, $subscriber->networks->enum());
        $this->assertSame(['Gmail', 'Yahoo'], $subscriber->networks->names());

        // Plain column (no enum bound) still casts.
        $this->assertInstanceOf(BitMask::class, $subscriber->toggles);
        $this->assertNull($subscriber->toggles->enum());
    }

    #[Test]
    public function it_accepts_flexible_values_when_setting_the_attribute(): void
    {
        $subscriber = new Subscriber(['email' => 'e@x.com']);
        $subscriber->networks = [Network::Gmail, Network::Hotmail];
        $subscriber->save();

        $fresh = Subscriber::where('email', 'e@x.com')->first();

        $this->assertSame(0b1001, $fresh->networks->value());
        $this->assertSame(0b1001, $fresh->getRawOriginal('networks'));
    }

    #[Test]
    public function it_serializes_masks_as_integers(): void
    {
        $array = Subscriber::where('email', 'b@x.com')->first()->toArray();

        $this->assertSame(3, $array['networks']);
    }

    #[Test]
    public function instance_helpers_read_and_mutate_masks(): void
    {
        $subscriber = Subscriber::where('email', 'a@x.com')->first();

        $this->assertTrue($subscriber->hasMask('networks', Network::Gmail));
        $this->assertFalse($subscriber->hasMask('networks', Network::Yahoo));
        $this->assertTrue($subscriber->hasAnyMask('networks', Network::Gmail, Network::Yahoo));
        $this->assertTrue($subscriber->missingMask('networks', Network::Outlook));

        $subscriber->addMask('networks', Network::Yahoo, Network::Outlook)->save();
        $this->assertSame(0b0111, $subscriber->fresh()->networks->value());

        $subscriber->removeMask('networks', Network::Gmail)->save();
        $this->assertSame(0b0110, $subscriber->fresh()->networks->value());

        $subscriber->toggleMask('networks', Network::Gmail, Network::Yahoo)->save();
        $this->assertSame(0b0101, $subscriber->fresh()->networks->value());

        $subscriber->setMask('networks', Network::Hotmail)->save();
        $this->assertSame(0b1000, $subscriber->fresh()->networks->value());

        $subscriber->clearMask('networks')->save();
        $this->assertSame(0, $subscriber->fresh()->networks->value());
    }

    #[Test]
    public function it_respects_explicit_casts_over_auto_registration(): void
    {
        $this->assertSame(AsBitMask::using(Network::class), (new Subscriber)->getCasts()['networks']);
        $this->assertSame(AsBitMask::class, (new Subscriber)->getCasts()['toggles']);
    }

    #[Test]
    public function where_mask_has_matches_rows_with_all_flags(): void
    {
        $emails = Subscriber::whereMaskHas('networks', Network::Gmail)->pluck('email')->all();
        $this->assertSame(['a@x.com', 'b@x.com'], $emails);

        $emails = Subscriber::whereMaskHas('networks', [Network::Gmail, Network::Yahoo])->pluck('email')->all();
        $this->assertSame(['b@x.com'], $emails);
    }

    #[Test]
    public function where_mask_has_any_matches_rows_with_at_least_one_flag(): void
    {
        $emails = Subscriber::whereMaskHasAny('networks', [Network::Yahoo, Network::Outlook])->pluck('email')->all();

        $this->assertSame(['b@x.com', 'c@x.com'], $emails);
    }

    #[Test]
    public function where_mask_missing_matches_rows_without_the_flags(): void
    {
        $emails = Subscriber::whereMaskMissing('networks', Network::Gmail)->pluck('email')->all();

        $this->assertSame(['c@x.com', 'd@x.com'], $emails);
    }

    #[Test]
    public function where_mask_equals_matches_exact_masks(): void
    {
        $emails = Subscriber::whereMaskEquals('networks', [Network::Gmail, Network::Yahoo])->pluck('email')->all();
        $this->assertSame(['b@x.com'], $emails);

        $emails = Subscriber::whereMaskEquals('networks', 0)->pluck('email')->all();
        $this->assertSame(['d@x.com'], $emails);
    }

    #[Test]
    public function or_scopes_combine_with_other_conditions(): void
    {
        $emails = Subscriber::whereMaskEquals('networks', 0)
            ->orWhereMaskHas('networks', Network::Outlook)
            ->pluck('email')
            ->all();

        $this->assertSame(['c@x.com', 'd@x.com'], $emails);
    }

    #[Test]
    public function scopes_can_target_plain_mask_columns_with_ints(): void
    {
        $emails = Subscriber::whereMaskHas('toggles', 0b10)->pluck('email')->all();

        $this->assertSame(['c@x.com', 'd@x.com'], $emails);
    }

    #[Test]
    public function collection_macros_filter_models_in_memory(): void
    {
        $subscribers = Subscriber::all();

        $this->assertSame(
            ['a@x.com', 'b@x.com'],
            $subscribers->whereMaskHas('networks', Network::Gmail)->pluck('email')->all()
        );
        $this->assertSame(
            ['b@x.com', 'c@x.com'],
            $subscribers->whereMaskHasAny('networks', [Network::Yahoo, Network::Outlook])->pluck('email')->all()
        );
        $this->assertSame(
            ['c@x.com', 'd@x.com'],
            $subscribers->whereMaskMissing('networks', Network::Gmail)->pluck('email')->all()
        );
        $this->assertSame(
            ['d@x.com'],
            $subscribers->whereMaskEquals('networks', 0)->pluck('email')->all()
        );
    }

    #[Test]
    public function collection_macros_work_on_plain_arrays(): void
    {
        $rows = collect([
            ['name' => 'one', 'mask' => 0b01],
            ['name' => 'two', 'mask' => 0b11],
        ]);

        $this->assertSame(['two'], $rows->whereMaskHas('mask', 0b10)->pluck('name')->all());
    }

    #[Test]
    public function bit_mask_accessor_returns_bound_masks(): void
    {
        $subscriber = Subscriber::where('email', 'b@x.com')->first();

        $mask = $subscriber->bitMask('networks');

        $this->assertSame([Network::Gmail, Network::Yahoo], $mask->flags());
        $this->assertSame(Network::class, $subscriber->bitMaskEnum('networks'));
        $this->assertNull($subscriber->bitMaskEnum('toggles'));
    }
}
