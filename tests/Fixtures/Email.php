<?php

namespace Zuko\BitMasks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Zuko\BitMasks\Concerns\HasBitMasks;

class Email extends Model
{
    use HasBitMasks;

    public $timestamps = false;

    protected $table = 'emails';

    protected $primaryKey = 'email';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $bitMasks = [
        'networks' => [
            'pivot' => 'email_networks',
            'enum' => PivotNetwork::class,
            'foreignPivotKey' => 'email',
            'flagKey' => 'network_id',
            'ownerKey' => 'email',
        ],
    ];
}
