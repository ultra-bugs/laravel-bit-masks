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
    public function boot(): void
    {
        static::registerCollectionMacros();
        static::registerBlueprintMacros();

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeBitMaskCommand::class,
            ]);
        }
    }

    /**
     * Collection macros mirroring the query scopes, for in-memory filtering.
     *
     * Work on Eloquent collections (reading cast attributes) as well as plain
     * collections of arrays/objects holding integer masks under $key.
     */
    public static function registerCollectionMacros(): void
    {
        Collection::macro('whereMaskHas', function (string $key, mixed $flags) {
            /** @var Collection $this */
            $mask = BitMask::resolve($flags);

            return $this->filter(static fn ($item) => (BitMask::resolve(data_get($item, $key)) & $mask) === $mask);
        });

        Collection::macro('whereMaskHasAny', function (string $key, mixed $flags) {
            /** @var Collection $this */
            $mask = BitMask::resolve($flags);

            return $this->filter(static fn ($item) => (BitMask::resolve(data_get($item, $key)) & $mask) !== 0);
        });

        Collection::macro('whereMaskMissing', function (string $key, mixed $flags) {
            /** @var Collection $this */
            $mask = BitMask::resolve($flags);

            return $this->filter(static fn ($item) => (BitMask::resolve(data_get($item, $key)) & $mask) === 0);
        });

        Collection::macro('whereMaskEquals', function (string $key, mixed $flags) {
            /** @var Collection $this */
            $mask = BitMask::resolve($flags);

            return $this->filter(static fn ($item) => BitMask::resolve(data_get($item, $key)) === $mask);
        });
    }

    /**
     * Schema helper: $table->bitMask('networks') — an unsigned BIGINT
     * defaulting to 0 (64 flags per column, bit 63 reserved for the sign
     * on engines that only store signed integers).
     */
    public static function registerBlueprintMacros(): void
    {
        Blueprint::macro('bitMask', function (string $column) {
            /** @var Blueprint $this */
            return $this->unsignedBigInteger($column)->default(0);
        });
    }
}
