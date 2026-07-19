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

namespace Zuko\BitMasks;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Zuko\BitMasks\Console\MakeBitMaskCommand;

/**
 * Registers the make:bitmask command, collection macros for in-memory mask
 * filtering, and the Blueprint::bitMask() schema macro.
 *
 * Macro registrars are public statics so they can be invoked without a
 * container (e.g. from package tests).
 */
class BitMasksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bit-masks.php', 'bit-masks');
    }

    public function boot(): void
    {
        static::registerCollectionMacros();
        static::registerBlueprintMacros();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/bit-masks.php' => $this->app->configPath('bit-masks.php'),
            ], 'bit-masks-config');

            $this->commands([
                MakeBitMaskCommand::class,
            ]);
        }
    }

    /**
     * Collection macros mirroring the query scopes, for in-memory filtering.
     *
     * Work on Eloquent collections (reading cast attributes) as well as plain
     * collections of arrays/objects holding integer masks under $key. Flag
     * NAMES in $flags resolve against the enum bound to each item's mask.
     */
    public static function registerCollectionMacros(): void
    {
        $resolve = static function (mixed $flags, mixed $value): array {
            $enum = $value instanceof BitMask ? $value->enum() : null;

            return [BitMask::resolve($value), BitMask::resolve($flags, $enum)];
        };

        Collection::macro('whereMaskHas', function (string $key, mixed $flags) use ($resolve) {
            /** @var Collection $this */
            return $this->filter(static function ($item) use ($key, $flags, $resolve) {
                [$value, $mask] = $resolve($flags, data_get($item, $key));

                return ($value & $mask) === $mask;
            });
        });

        Collection::macro('whereMaskHasAny', function (string $key, mixed $flags) use ($resolve) {
            /** @var Collection $this */
            return $this->filter(static function ($item) use ($key, $flags, $resolve) {
                [$value, $mask] = $resolve($flags, data_get($item, $key));

                return ($value & $mask) !== 0;
            });
        });

        Collection::macro('whereMaskMissing', function (string $key, mixed $flags) use ($resolve) {
            /** @var Collection $this */
            return $this->filter(static function ($item) use ($key, $flags, $resolve) {
                [$value, $mask] = $resolve($flags, data_get($item, $key));

                return ($value & $mask) === 0;
            });
        });

        Collection::macro('whereMaskEquals', function (string $key, mixed $flags) use ($resolve) {
            /** @var Collection $this */
            return $this->filter(static function ($item) use ($key, $flags, $resolve) {
                [$value, $mask] = $resolve($flags, data_get($item, $key));

                return $value === $mask;
            });
        });
    }

    /**
     * Schema helpers:
     *
     *   $table->bitMask('toggles');             // one unsigned BIGINT default 0
     *   $table->wideBitMask('networks', 2);     // networks_1, networks_2 (default 0)
     *
     * Each column stores 63 usable flags (bit 63 reserved for the sign on engines
     * that only store signed integers), so a wide mask of N columns holds N × 63.
     */
    public static function registerBlueprintMacros(): void
    {
        Blueprint::macro('bitMask', function (string $column) {
            /** @var Blueprint $this */
            return $this->unsignedBigInteger($column)->default(0);
        });

        Blueprint::macro('wideBitMask', function (string $name, int $columns = 2) {
            /** @var Blueprint $this */
            for ($i = 1; $i <= $columns; $i++) {
                $this->unsignedBigInteger($name . '_' . $i)->default(0);
            }

            return $this;
        });

        // Junction table for the pivot storage strategy. Adds the flag-id column,
        // a composite primary key with the (already-defined) owner column, and a
        // reverse index for "which owners carry flag X?" lookups.
        //
        //   Schema::create('email_tags', function (Blueprint $table) {
        //       $table->string('email');       // owner column (type is yours)
        //       $table->flagPivot('email');    // adds flag_id + keys/indexes
        //   });
        Blueprint::macro('flagPivot', function (string $ownerColumn, string $flagColumn = 'flag_id') {
            /** @var Blueprint $this */
            $this->unsignedBigInteger($flagColumn);
            $this->primary([$ownerColumn, $flagColumn]);
            $this->index([$flagColumn, $ownerColumn]);

            return $this;
        });
    }
}
