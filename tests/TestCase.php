<?php

namespace Zuko\BitMasks\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Zuko\BitMasks\BitMasksServiceProvider;

abstract class TestCase extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Registered statically so tests run without a Laravel container,
        // both standalone and from the host application's phpunit.
        BitMasksServiceProvider::registerCollectionMacros();
        BitMasksServiceProvider::registerBlueprintMacros();
    }
}
