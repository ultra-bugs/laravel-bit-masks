<?php

namespace Zuko\BitMasks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Zuko\BitMasks\Concerns\HasBitMasks;

class Recipient extends Model
{
    use HasBitMasks;

    public $timestamps = false;

    protected $table = 'recipients';

    protected $guarded = [];

    protected $bitMasks = [
        'networks' => ['columns' => 2, 'enum' => WideNetwork::class],
    ];
}
