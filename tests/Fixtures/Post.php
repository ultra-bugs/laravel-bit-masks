<?php

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
