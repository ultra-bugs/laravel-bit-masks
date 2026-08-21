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
