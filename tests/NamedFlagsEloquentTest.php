<?php

namespace Zuko\BitMasks\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\BitMask;
use Zuko\BitMasks\Tests\Fixtures\Email;
use Zuko\BitMasks\Tests\Fixtures\Network;
use Zuko\BitMasks\Tests\Fixtures\PivotNetwork;
use Zuko\BitMasks\Tests\Fixtures\Recipient;
use Zuko\BitMasks\Tests\Fixtures\Subscriber;
use Zuko\BitMasks\Tests\Fixtures\WideNetwork;

/**
 * Flag names (strings) flowing through the Eloquent surface: casts, helpers,
 * query scopes and collection macros — for all three storage strategies.
 */
class NamedFlagsEloquentTest extends TestCase
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

        Capsule::schema()->create('recipients', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->wideBitMask('networks', 2);
        });

        Capsule::schema()->create('emails', function (Blueprint $table) {
            $table->string('email')->primary();
        });

        Capsule::schema()->create('email_networks', function (Blueprint $table) {
            $table->string('email');
            $table->flagPivot('email', 'network_id');
        });

        Subscriber::insert([
            ['email' => 'a@x.com', 'networks' => BitMask::resolve(Network::Gmail), 'toggles' => 0],
            ['email' => 'b@x.com', 'networks' => BitMask::resolve([Network::Gmail, Network::Yahoo]), 'toggles' => 0],
            ['email' => 'c@x.com', 'networks' => 0, 'toggles' => 0],
        ]);
    }

    #[Test]
    public function attributes_accept_names_on_assignment(): void
    {
        $subscriber = Subscriber::where('email', 'c@x.com')->first();
        $subscriber->networks = ['gmail', 'hotmail'];
        $subscriber->save();

        $this->assertSame(0b1001, $subscriber->fresh()->networks->value());
    }

    #[Test]
    public function mask_helpers_accept_names(): void
    {
        $subscriber = Subscriber::where('email', 'b@x.com')->first();

        $this->assertTrue($subscriber->hasMask('networks', 'gmail'));
        $this->assertTrue($subscriber->hasAnyMask('networks', 'outlook', 'yahoo'));
        $this->assertTrue($subscriber->missingMask('networks', 'hotmail'));

        $subscriber->addMask('networks', 'outlook')->removeMask('networks', 'gmail')->save();

        $this->assertSame(['Yahoo', 'Outlook'], $subscriber->fresh()->networks->names());

        $subscriber->setMask('networks', ['gmail', 'yahoo'])->save();

        $this->assertSame(3, $subscriber->fresh()->networks->value());
    }

    #[Test]
    public function query_scopes_accept_names(): void
    {
        $this->assertSame(2, Subscriber::whereMaskHas('networks', 'gmail')->count());
        $this->assertSame(2, Subscriber::whereMaskHasAny('networks', ['yahoo', 'outlook', 'gmail'])->count());
        $this->assertSame(1, Subscriber::whereMaskMissing('networks', 'gmail')->count());
        $this->assertSame(1, Subscriber::whereMaskEquals('networks', ['gmail', 'yahoo'])->count());
    }

    #[Test]
    public function collection_macros_resolve_names_against_each_items_enum(): void
    {
        $subscribers = Subscriber::all();

        $this->assertCount(2, $subscribers->whereMaskHas('networks', 'gmail'));
        $this->assertCount(2, $subscribers->whereMaskHasAny('networks', ['yahoo', 'gmail']));
        $this->assertCount(1, $subscribers->whereMaskMissing('networks', 'gmail'));
        $this->assertCount(1, $subscribers->whereMaskEquals('networks', ['gmail', 'yahoo']));
    }

    #[Test]
    public function wide_masks_accept_names_end_to_end(): void
    {
        $recipient = Recipient::create(['email' => 'w@x.com']);
        $recipient->addMask('networks', 'gmail', 'proton')->save();

        $recipient = $recipient->fresh();

        $this->assertTrue($recipient->hasMask('networks', 'proton'));
        $this->assertSame(['Gmail', 'Proton'], $recipient->networks->names());
        $this->assertSame(1, Recipient::whereMaskHas('networks', 'proton')->count());
        $this->assertSame(1, Recipient::whereMaskHasAny('networks', ['icloud', 'gmail'])->count());

        $recipient->networks = ['icloud'];
        $recipient->save();

        $this->assertSame([WideNetwork::Icloud], $recipient->fresh()->networks->flags());
    }

    #[Test]
    public function pivot_masks_accept_names_end_to_end(): void
    {
        $email = Email::create(['email' => 'p@x.com']);
        $email->addMask('networks', 'gmail', 'proton')->save();

        $email = $email->fresh();

        $this->assertTrue($email->hasMask('networks', 'gmail'));
        $this->assertSame([1, 100], $email->networks->ids());
        $this->assertSame(1, Email::whereMaskHas('networks', 'proton')->count());
        $this->assertSame(0, Email::whereMaskMissing('networks', 'gmail')->count());

        $email->setMask('networks', ['icloud']);
        $email->save();

        $this->assertSame([PivotNetwork::Icloud], $email->fresh()->networks->flags());
    }
}
