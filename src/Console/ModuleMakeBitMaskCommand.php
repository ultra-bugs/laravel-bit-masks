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

/**
 * Bridge command for nwidart/laravel-modules.
 *
 * Mirrors the nwidart convention: module is a positional argument that
 * falls back to `module:use`'s stored module when omitted.
 *
 *   module:use Blog
 *   php artisan module:make-bitmask Network --flags="gmail,yahoo"
 *   # equivalent to: make:bitmask Network --flags="gmail,yahoo" --module=Blog
 *
 *   php artisan module:make-bitmask Network --flags="gmail,yahoo" Core
 *   # explicit module argument overrides the stored one
 */
class ModuleMakeBitMaskCommand extends MakeBitMaskCommand
{
    protected $signature = 'module:make-bitmask
        {name : Class name for the generated flags (e.g. NetworkFlags)}
        {module? : Module name (falls back to the module set by module:use)}
        {--flags= : Comma-separated flag names (e.g. "gmail,yahoo,outlook")}
        {--from-file= : Path to a file containing one flag name per line}
        {--type= : Output type: "enum" (int-backed enum) or "constants" (final class)}
        {--namespace= : Target namespace (overrides module-derived namespace)}
        {--path= : Target directory (overrides module-derived path)}
        {--start=0 : Bit position assigned to the first flag}
        {--force : Overwrite the file if it already exists}';

    protected $description = 'Generate a bitmask flags class inside a nwidart/laravel-modules module';

    /**
     * Resolve the module name from the positional argument or the stored
     * "used" module (set by `module:use`).
     */
    protected function getModuleName(): string
    {
        if (! $this->laravel->bound('modules')) {
            throw new \RuntimeException(
                'This command requires nwidart/laravel-modules. '
                . 'Install it with: composer require nwidart/laravel-modules'
            );
        }

        $name = $this->argument('module');

        if (! $name) {
            $name = $this->laravel['modules']->getUsedNow();
        }

        $module = $this->laravel['modules']->find($name);

        if ($module === null) {
            throw new \InvalidArgumentException(sprintf('Module [%s] not found.', $name));
        }

        return $module->getStudlyName();
    }

    /**
     * Always resolve via getModuleName() — the bridge command is inherently
     * module-scoped, so the --module option from the parent is not needed.
     */
    protected function resolveModule(): object
    {
        $name = $this->getModuleName();

        return $this->laravel['modules']->findOrFail($name);
    }

    protected function resolveNamespace(): string
    {
        if ($explicit = $this->option('namespace')) {
            return trim((string) $explicit, '\\');
        }

        return $this->moduleNamespace();
    }

    protected function targetDirectory(): string
    {
        if ($explicit = $this->option('path')) {
            return $this->resolveAbsolutePath((string) $explicit);
        }

        return $this->moduleDirectory();
    }
}
