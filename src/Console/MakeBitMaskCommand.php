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
            namespace: trim((string) ($this->option('namespace') ?: $this->generatorConfig('namespace', 'App\\BitMasks')), '\\'),
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
     * The directory generated files are written to: --path, else the configured
     * default (relative paths resolve from the app base path), else app/BitMasks.
     */
    protected function targetDirectory(): string
    {
        $directory = (string) ($this->option('path') ?: $this->generatorConfig('path', ''));

        if ($directory === '') {
            return $this->laravel->path('BitMasks');
        }

        $isAbsolute = str_starts_with($directory, '/')
            || str_starts_with($directory, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $directory) === 1;

        return $isAbsolute ? $directory : $this->laravel->basePath($directory);
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
