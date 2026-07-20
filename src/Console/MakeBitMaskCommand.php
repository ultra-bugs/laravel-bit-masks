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

namespace Zuko\BitMasks\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Zuko\BitMasks\Support\BitMaskClassBuilder;

/**
 * Generates a bitmask flag class (int-backed enum or constants class)
 * with sequential power-of-two values.
 *
 * Examples:
 *   php artisan make:bitmask Network --flags="gmail,yahoo,outlook"
 *   php artisan make:bitmask Network --from-file=networks.txt --type=constants
 *   php artisan make:bitmask Network --flags=gmail --namespace="Modules\Core\BitMasks" --path=modules/Core/app/BitMasks
 *   php artisan make:bitmask Network --flags=gmail --module=Blog
 */
class MakeBitMaskCommand extends Command
{
    protected $signature = 'make:bitmask
        {name : Class name for the generated flags (e.g. NetworkFlags)}
        {--flags= : Comma-separated flag names (e.g. "gmail,yahoo,outlook")}
        {--from-file= : Path to a file containing one flag name per line}
        {--type= : Output type: "enum" (int-backed enum) or "constants" (final class)}
        {--namespace= : Target namespace (default: config bit-masks.generator.namespace)}
        {--path= : Target directory (default: config bit-masks.generator.path)}
        {--module= : Generate inside a nwidart/laravel-modules module}
        {--start=0 : Bit position assigned to the first flag}
        {--force : Overwrite the file if it already exists}';

    protected $description = 'Generate a bitmask flags class (enum or constants) with power-of-two values';

    public function handle(): int
    {
        $flags = $this->flagNames();

        if ($flags === []) {
            $this->components->error('No flag names provided. Use --flags, --from-file or answer the prompt.');

            return self::INVALID;
        }

        $type = strtolower((string) ($this->option('type') ?: $this->generatorConfig('type', BitMaskClassBuilder::TYPE_ENUM)));

        $builder = new BitMaskClassBuilder(
            class: (string) $this->argument('name'),
            namespace: $this->resolveNamespace(),
            type: $type,
            flags: $flags,
            startBit: (int) $this->option('start'),
        );

        try {
            $source = $builder->build();
            $class = $builder->className();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $directory = $this->targetDirectory();
        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $class . '.php';

        if (file_exists($path) && ! $this->option('force')) {
            $this->components->error(sprintf('File [%s] already exists. Use --force to overwrite.', $path));

            return self::FAILURE;
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $source);

        $this->components->info(sprintf('Bitmask %s [%s] created successfully (%d flags, bits %d..%d).',
            $type,
            $path,
            count($flags),
            (int) $this->option('start'),
            (int) $this->option('start') + count($flags) - 1
        ));

        return self::SUCCESS;
    }

    /**
     * A generator default from config/bit-masks.php, when the option is omitted.
     */
    protected function generatorConfig(string $key, ?string $default = null): ?string
    {
        $value = $this->laravel['config']->get('bit-masks.generator.' . $key, $default);

        return $value === null ? null : (string) $value;
    }

    /**
     * Resolve the target namespace.
     *
     * Priority: explicit --namespace > --module derived > config > hardcoded default.
     */
    protected function resolveNamespace(): string
    {
        if ($explicit = $this->option('namespace')) {
            return trim((string) $explicit, '\\');
        }

        if ($this->option('module')) {
            return $this->moduleNamespace();
        }

        return trim((string) $this->generatorConfig('namespace', 'App\\BitMasks'), '\\');
    }

    /**
     * The directory generated files are written to.
     *
     * Priority: explicit --path > --module derived > config > app/BitMasks.
     */
    protected function targetDirectory(): string
    {
        if ($explicit = $this->option('path')) {
            return $this->resolveAbsolutePath((string) $explicit);
        }

        if ($this->option('module')) {
            return $this->moduleDirectory();
        }

        $directory = (string) $this->generatorConfig('path', '');

        if ($directory === '') {
            return $this->laravel->path('BitMasks');
        }

        return $this->resolveAbsolutePath($directory);
    }

    protected function resolveAbsolutePath(string $directory): string
    {
        $isAbsolute = str_starts_with($directory, '/')
            || str_starts_with($directory, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $directory) === 1;

        return $isAbsolute ? $directory : $this->laravel->basePath($directory);
    }

    // ---- Module support (nwidart/laravel-modules) -------------------------

    /**
     * Resolve the nwidart Module instance for the --module option.
     *
     * @throws \RuntimeException           when nwidart/laravel-modules is not installed
     * @throws InvalidArgumentException    when the named module does not exist
     */
    protected function resolveModule(): object
    {
        $name = (string) $this->option('module');

        if (! $this->laravel->bound('modules')) {
            throw new \RuntimeException(
                'The --module option requires nwidart/laravel-modules. '
                . 'Install it with: composer require nwidart/laravel-modules'
            );
        }

        $module = $this->laravel['modules']->find($name);

        if ($module === null) {
            throw new InvalidArgumentException(sprintf('Module [%s] not found.', $name));
        }

        return $module;
    }

    /**
     * Namespace inside a module: {modules.namespace}\{Module}\{sub-namespace}.
     *
     * The sub-namespace is derived from the generator config by stripping its
     * first segment (typically "App"), so `App\BitMasks` → `BitMasks` and
     * `App\Enums\Flags` → `Enums\Flags`.
     */
    protected function moduleNamespace(): string
    {
        $module = $this->resolveModule();
        $moduleNs = rtrim((string) $this->laravel['config']->get('modules.namespace', 'Modules'), '\\');
        $subNs = $this->generatorSubNamespace();

        return $moduleNs . '\\' . $module->getStudlyName() . '\\' . $subNs;
    }

    /**
     * Directory inside a module: {module_path}/{app_folder}/{sub-path}.
     *
     * The sub-path is derived from the generator config by stripping its first
     * segment (typically "app"), so `app/BitMasks` → `BitMasks`.
     */
    protected function moduleDirectory(): string
    {
        $module = $this->resolveModule();
        $appFolder = rtrim((string) $this->laravel['config']->get('modules.paths.app_folder', 'app'), '/');
        $subPath = $this->generatorSubPath();

        return $module->getExtraPath($appFolder . '/' . $subPath);
    }

    protected function generatorSubNamespace(): string
    {
        $namespace = (string) $this->generatorConfig('namespace', 'App\\BitMasks');
        $pos = strpos($namespace, '\\');

        return $pos !== false ? substr($namespace, $pos + 1) : 'BitMasks';
    }

    protected function generatorSubPath(): string
    {
        $path = str_replace('\\', '/', (string) $this->generatorConfig('path', 'app/BitMasks'));
        $pos = strpos($path, '/');

        return $pos !== false ? substr($path, $pos + 1) : 'BitMasks';
    }

    /**
     * Collect flag names from --flags, --from-file, or an interactive prompt.
     *
     * @return list<string>
     */
    protected function flagNames(): array
    {
        $flags = [];

        if ($file = $this->option('from-file')) {
            if (! is_file($file)) {
                $this->components->error(sprintf('Flag file [%s] not found.', $file));

                return [];
            }

            $flags = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        }

        if ($option = $this->option('flags')) {
            $flags = array_merge($flags, explode(',', (string) $option));
        }

        if ($flags === [] && $this->input->isInteractive()) {
            $answer = (string) $this->ask('Flag names (comma-separated, in bit order)');
            $flags = $answer === '' ? [] : explode(',', $answer);
        }

        return array_values(array_filter(array_map('trim', $flags), static fn (string $flag) => $flag !== ''));
    }
}
