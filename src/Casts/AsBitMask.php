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

namespace Zuko\BitMasks\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Zuko\BitMasks\BitMask;

/**
 * Eloquent cast turning an integer column into a {@see BitMask} value object.
 *
 * Usage:
 *   protected $casts = [
 *       'networks' => AsBitMask::class,                     // plain mask
 *       'networks' => AsBitMask::using(Network::class),     // mask bound to a flag enum
 *   ];
 *
 * Setting the attribute accepts anything BitMask::resolve() understands:
 * ints, enum cases, BitMask instances or iterables of those.
 *
 * @implements CastsAttributes<BitMask, int>
 */
class AsBitMask implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * @param  class-string<BackedEnum>|null  $enum
     */
    public function __construct(protected ?string $enum = null)
    {
    }

    /**
     * Cast definition string binding the given flag enum.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function using(string $enum): string
    {
        return static::class . ':' . $enum;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): BitMask
    {
        return BitMask::from($value, $this->enum);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return BitMask::resolve($value);
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): int
    {
        return BitMask::resolve($value);
    }
}
