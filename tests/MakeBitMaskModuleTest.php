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
 *          * * * * * * * * * * * * * * * * * * * * * * *
 *          * -    - -   F.R.E.E.M.I.N.D   - -    - *
 *          * -  Copyright © 2026 (Z) Programing  - *
 *          *    -  -  All Rights Reserved  -  -    *
 *          * * * * * * * * * * * * * * * * * * * * * * *
 */

namespace Zuko\BitMasks\Tests;

use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zuko\BitMasks\Console\MakeBitMaskCommand;

/**
 * Tests the --module option on make:bitmask, covering module namespace
 * and path resolution against nwidart/laravel-modules config values.
 *
 * Since tests run containerless, we build a minimal Container with config
 * and (optionally) a fake "modules" binding that mimics nwidart's repository.
 */
class MakeBitMaskModuleTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bitmask_module_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);

        parent::tearDown();
    }

    #[Test]
    public function module_option_throws_when_nwidart_is_not_installed(): void
    {
        $app = $this->makeApp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nwidart\/laravel-modules/');

        $this->runCommand($app, [
            'name' => 'Network',
            '--flags' => 'gmail',
            '--module' => 'Blog',
        ]);
    }

    #[Test]
    public function module_option_throws_when_module_not_found(): void
    {
        $app = $this->makeApp();
        $app->instance('modules', new FakeModuleRepository([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Module \[Ghost\] not found/');

        $this->runCommand($app, [
            'name' => 'Network',
            '--flags' => 'gmail',
            '--module' => 'Ghost',
        ]);
    }

    #[Test]
    public function module_option_generates_into_module_directory(): void
    {
        $modulePath = $this->tempDir . '/Modules/Blog';
        mkdir($modulePath, 0755, true);

        $app = $this->makeApp();
        $app->instance('modules', new FakeModuleRepository([
            'blog' => new FakeModule('Blog', $modulePath),
        ]));

        $exitCode = $this->runCommand($app, [
            'name' => 'Network',
            '--flags' => 'gmail,yahoo',
            '--module' => 'Blog',
        ]);

        $this->assertSame(0, $exitCode);

        $generatedFile = $modulePath . '/app/BitMasks/Network.php';
        $this->assertFileExists($generatedFile);

        $source = file_get_contents($generatedFile);
        $this->assertStringContainsString('namespace Modules\\Blog\\BitMasks;', $source);
        $this->assertStringContainsString('case Gmail = 1 << 0;', $source);
        $this->assertStringContainsString('case Yahoo = 1 << 1;', $source);
    }

    #[Test]
    public function module_respects_custom_nwidart_config(): void
    {
        $modulePath = $this->tempDir . '/custom-modules/core';
        mkdir($modulePath, 0755, true);

        $app = $this->makeApp([
            'modules.namespace' => 'Custom\\Modules',
            'modules.paths.app_folder' => 'src',
        ]);
        $app->instance('modules', new FakeModuleRepository([
            'core' => new FakeModule('Core', $modulePath),
        ]));

        $exitCode = $this->runCommand($app, [
            'name' => 'Permissions',
            '--flags' => 'read,write,admin',
            '--module' => 'Core',
        ]);

        $this->assertSame(0, $exitCode);

        $generatedFile = $modulePath . '/src/BitMasks/Permissions.php';
        $this->assertFileExists($generatedFile);

        $source = file_get_contents($generatedFile);
        $this->assertStringContainsString('namespace Custom\\Modules\\Core\\BitMasks;', $source);
    }

    #[Test]
    public function module_respects_custom_generator_sub_path(): void
    {
        $modulePath = $this->tempDir . '/Modules/Shop';
        mkdir($modulePath, 0755, true);

        $app = $this->makeApp([
            'bit-masks.generator.namespace' => 'App\\Enums\\Flags',
            'bit-masks.generator.path' => 'app/Enums/Flags',
        ]);
        $app->instance('modules', new FakeModuleRepository([
            'shop' => new FakeModule('Shop', $modulePath),
        ]));

        $exitCode = $this->runCommand($app, [
            'name' => 'Status',
            '--flags' => 'active,archived',
            '--module' => 'Shop',
        ]);

        $this->assertSame(0, $exitCode);

        $generatedFile = $modulePath . '/app/Enums/Flags/Status.php';
        $this->assertFileExists($generatedFile);

        $source = file_get_contents($generatedFile);
        $this->assertStringContainsString('namespace Modules\\Shop\\Enums\\Flags;', $source);
    }

    #[Test]
    public function explicit_namespace_overrides_module_derived_namespace(): void
    {
        $modulePath = $this->tempDir . '/Modules/Blog';
        mkdir($modulePath, 0755, true);

        $app = $this->makeApp();
        $app->instance('modules', new FakeModuleRepository([
            'blog' => new FakeModule('Blog', $modulePath),
        ]));

        $exitCode = $this->runCommand($app, [
            'name' => 'Tags',
            '--flags' => 'hot,trending',
            '--module' => 'Blog',
            '--namespace' => 'Custom\\Tags',
        ]);

        $this->assertSame(0, $exitCode);

        $source = file_get_contents($modulePath . '/app/BitMasks/Tags.php');
        $this->assertStringContainsString('namespace Custom\\Tags;', $source);
    }

    #[Test]
    public function explicit_path_overrides_module_derived_path(): void
    {
        $customDir = $this->tempDir . '/custom-output';
        $modulePath = $this->tempDir . '/Modules/Blog';
        mkdir($modulePath, 0755, true);

        $app = $this->makeApp();
        $app->instance('modules', new FakeModuleRepository([
            'blog' => new FakeModule('Blog', $modulePath),
        ]));

        $exitCode = $this->runCommand($app, [
            'name' => 'Tags',
            '--flags' => 'hot',
            '--module' => 'Blog',
            '--path' => $customDir,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($customDir . '/Tags.php');
        $this->assertFileDoesNotExist($modulePath . '/app/BitMasks/Tags.php');
    }

    // ------- Helpers -------------------------------------------------------

    private function makeApp(array $config = []): FakeApp
    {
        $base = [
            'bit-masks' => require __DIR__ . '/../config/bit-masks.php',
            'modules' => [
                'namespace' => 'Modules',
                'paths' => ['app_folder' => 'app'],
            ],
        ];

        $app = new FakeApp($this->tempDir);
        $app->instance('config', new FakeConfig(array_replace_recursive($base, $this->dotToNested($config))));

        return $app;
    }

    private function runCommand(Container $app, array $params): int
    {
        $command = new MakeBitMaskCommand;
        $command->setLaravel($app);

        $input = new ArrayInput($params, $command->getDefinition());
        $input->setInteractive(false);
        $output = new OutputStyle($input, new BufferedOutput);

        return $command->run($input, $output);
    }

    private function dotToNested(array $config): array
    {
        $nested = [];

        foreach ($config as $key => $value) {
            $keys = explode('.', $key);
            $ref = &$nested;

            foreach ($keys as $segment) {
                if (! isset($ref[$segment]) || ! is_array($ref[$segment])) {
                    $ref[$segment] = [];
                }
                $ref = &$ref[$segment];
            }

            $ref = $value;
        }

        return $nested;
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }

        rmdir($dir);
    }
}

/**
 * Minimal Application stand-in with the methods the Console\Command base
 * class expects during execution (runningUnitTests, path, basePath, etc.).
 */
class FakeApp extends Container
{
    public function __construct(private readonly string $base)
    {
    }

    public function runningUnitTests(): bool
    {
        return true;
    }

    public function path(string $path = ''): string
    {
        return $this->base . '/app' . ($path ? '/' . $path : '');
    }

    public function basePath(string $path = ''): string
    {
        return $this->base . ($path ? '/' . $path : '');
    }

    public function environment(): string
    {
        return 'testing';
    }
}

/**
 * Minimal stand-in for Illuminate\Config\Repository, so tests run without
 * the illuminate/config package.
 */
class FakeConfig
{
    public function __construct(private array $items)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

/**
 * Minimal stand-in for nwidart's module repository, so tests don't need
 * the real package installed.
 */
class FakeModuleRepository
{
    /** @param array<string, FakeModule> $modules keyed by lowercase name */
    public function __construct(private readonly array $modules)
    {
    }

    public function find(string $name): ?FakeModule
    {
        return $this->modules[strtolower($name)] ?? null;
    }
}

/**
 * Minimal stand-in for Nwidart\Modules\Module.
 */
class FakeModule
{
    public function __construct(
        private readonly string $name,
        private readonly string $path,
    ) {
    }

    public function getStudlyName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getExtraPath(?string $path): string
    {
        return $this->getPath() . ($path ? '/' . $path : '');
    }
}
