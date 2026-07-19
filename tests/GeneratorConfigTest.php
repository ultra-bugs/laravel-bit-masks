<?php

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
