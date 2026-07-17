<?php

namespace Zuko\BitMasks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Zuko\BitMasks\Concerns\HasBitMasks;

class Subscriber extends Model
{
    use HasBitMasks;

    public $timestamps = false;

    protected $table = 'subscribers';

    protected $guarded = [];

    protected $bitMasks = [
        'networks' => Network::class,
        'toggles',
    ];
}
