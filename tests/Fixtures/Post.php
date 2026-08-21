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

/**
 * Pivot mask relying entirely on derived defaults: ownerKey = 'id',
 * foreignPivotKey = 'post_id', flagKey = 'flag_id', no bound enum.
 */
class Post extends Model
{
    use HasBitMasks;

    protected $table = 'posts';

    protected $bitMasks = [
        'labels' => ['pivot' => 'post_labels'],
    ];
}
