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

use PHPUnit\Framework\Attributes\Test;
use Zuko\BitMasks\Support\BitMaskClassBuilder;

class GeneratorConfigTest extends TestCase
{
    #[Test]
    public function the_config_file_ships_the_generator_defaults(): void
    {
        $config = require __DIR__ . '/../config/bit-masks.php';

        $this->assertSame('App\\BitMasks', $config['generator']['namespace']);
        $this->assertSame('app/BitMasks', $config['generator']['path']);
        $this->assertSame(BitMaskClassBuilder::TYPE_ENUM, $config['generator']['type']);
    }
}
