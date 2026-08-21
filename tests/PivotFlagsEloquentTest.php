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

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\FlagSet;
use Zuko\BitMasks\Tests\Fixtures\Email;
use Zuko\BitMasks\Tests\Fixtures\PivotNetwork;
use Zuko\BitMasks\Tests\Fixtures\Post;

class PivotFlagsEloquentTest extends TestCase
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

        Capsule::schema()->create('emails', function (Blueprint $table) {
            $table->string('email')->primary();
        });

        Capsule::schema()->create('email_networks', function (Blueprint $table) {
            $table->string('email');
            $table->flagPivot('email', 'network_id');
        });

        $this->seed('a@x.com', [PivotNetwork::Gmail]);
        $this->seed('b@x.com', [PivotNetwork::Gmail, PivotNetwork::Proton]);
        $this->seed('c@x.com', [PivotNetwork::Icloud]);
        $this->seed('d@x.com', []);
    }

    private function seed(string $email, array $flags): void
    {
        $model = new Email(['email' => $email]);
        $model->networks = $flags; // buffered
        $model->save();            // insert row + flush pivot
    }

    private function junctionCount(string $email): int
    {
        return Capsule::connection()->table('email_networks')->where('email', $email)->count();
    }

    #[Test]
    public function it_derives_pivot_defaults_from_the_model(): void
    {
        $definition = (new Post)->pivotFlagDefinition('labels');

        $this->assertNotNull($definition);
        $this->assertSame('post_labels', $definition->table);
        $this->assertSame('post_id', $definition->foreignPivotKey);
        $this->assertSame('flag_id', $definition->flagKey);
        $this->assertSame('id', $definition->ownerKey);
        $this->assertNull($definition->enum);
    }

    #[Test]
    public function the_blueprint_macro_creates_the_flag_column(): void
    {
        $this->assertTrue(Capsule::schema()->hasColumn('email_networks', 'network_id'));
    }

    #[Test]
    public function assigning_flags_writes_junction_rows_on_save(): void
    {
        $this->assertSame(2, $this->junctionCount('b@x.com'));
        $this->assertSame(0, $this->junctionCount('d@x.com'));

        $ids = Capsule::connection()->table('email_networks')
            ->where('email', 'b@x.com')->orderBy('network_id')->pluck('network_id')->all();

        $this->assertSame([1, 100], array_map('intval', $ids));
    }

    #[Test]
    public function the_virtual_attribute_reads_back_as_a_flag_set(): void
    {
        $email = Email::find('b@x.com');

        $this->assertInstanceOf(FlagSet::class, $email->networks);
        $this->assertSame(['Gmail', 'Proton'], $email->networks->names());
        $this->assertTrue($email->networks->has(PivotNetwork::Proton));
    }

    #[Test]
    public function pending_flags_are_visible_before_save(): void
    {
        $email = new Email(['email' => 'e@x.com']);
        $email->networks = [PivotNetwork::Yahoo];

        // Not flushed yet, but the accessor reflects the buffered value.
        $this->assertSame(['Yahoo'], $email->networks->names());
        $this->assertSame(0, $this->junctionCount('e@x.com'));

        $email->save();
        $this->assertSame(1, $this->junctionCount('e@x.com'));
    }

    #[Test]
    public function instance_helpers_read_and_mutate_pivot_flags(): void
    {
        $email = Email::find('a@x.com');

        $this->assertTrue($email->hasMask('networks', PivotNetwork::Gmail));
        $this->assertFalse($email->hasMask('networks', PivotNetwork::Icloud));
        $this->assertTrue($email->missingMask('networks', PivotNetwork::Icloud));

        $email->addMask('networks', PivotNetwork::Icloud)->save();
        $this->assertTrue(Email::find('a@x.com')->hasMask('networks', PivotNetwork::Icloud));
        $this->assertSame(2, $this->junctionCount('a@x.com'));

        $email->removeMask('networks', PivotNetwork::Gmail)->save();
        $this->assertSame(['Icloud'], Email::find('a@x.com')->networks->names());

        $email->toggleMask('networks', PivotNetwork::Gmail, PivotNetwork::Icloud)->save();
        $this->assertSame(['Gmail'], Email::find('a@x.com')->networks->names());

        $email->setMask('networks', [PivotNetwork::Yahoo, PivotNetwork::Outlook])->save();
        $this->assertSame(['Yahoo', 'Outlook'], Email::find('a@x.com')->networks->names());

        $email->clearMask('networks')->save();
        $this->assertSame(0, $this->junctionCount('a@x.com'));
    }

    #[Test]
    public function where_mask_has_matches_rows_with_all_flags(): void
    {
        $emails = Email::whereMaskHas('networks', PivotNetwork::Gmail)->orderBy('email')->pluck('email')->all();
        $this->assertSame(['a@x.com', 'b@x.com'], $emails);

        $emails = Email::whereMaskHas('networks', [PivotNetwork::Gmail, PivotNetwork::Proton])->pluck('email')->all();
        $this->assertSame(['b@x.com'], $emails);
    }

    #[Test]
    public function where_mask_has_any_matches_rows_with_at_least_one_flag(): void
    {
        $emails = Email::whereMaskHasAny('networks', [PivotNetwork::Proton, PivotNetwork::Icloud])
            ->orderBy('email')->pluck('email')->all();

        $this->assertSame(['b@x.com', 'c@x.com'], $emails);
    }

    #[Test]
    public function where_mask_missing_matches_rows_without_the_flags(): void
    {
        $emails = Email::whereMaskMissing('networks', PivotNetwork::Gmail)
            ->orderBy('email')->pluck('email')->all();

        $this->assertSame(['c@x.com', 'd@x.com'], $emails);
    }

    #[Test]
    public function where_mask_equals_matches_exact_sets(): void
    {
        $emails = Email::whereMaskEquals('networks', [PivotNetwork::Gmail, PivotNetwork::Proton])->pluck('email')->all();
        $this->assertSame(['b@x.com'], $emails);

        $emails = Email::whereMaskEquals('networks', PivotNetwork::Gmail)->pluck('email')->all();
        $this->assertSame(['a@x.com'], $emails);

        // Exact empty set = rows with no flags at all.
        $emails = Email::whereMaskEquals('networks', [])->pluck('email')->all();
        $this->assertSame(['d@x.com'], $emails);
    }

    #[Test]
    public function or_scopes_compose_with_other_conditions(): void
    {
        $emails = Email::whereMaskEquals('networks', [])
            ->orWhereMaskHas('networks', PivotNetwork::Icloud)
            ->orderBy('email')
            ->pluck('email')
            ->all();

        $this->assertSame(['c@x.com', 'd@x.com'], $emails);
    }

    #[Test]
    public function deleting_a_model_purges_its_junction_rows(): void
    {
        Email::find('b@x.com')->delete();

        $this->assertSame(0, $this->junctionCount('b@x.com'));
    }
}
