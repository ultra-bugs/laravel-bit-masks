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

return [

    /*
    |--------------------------------------------------------------------------
    | Generator defaults (`php artisan make:bitmask`)
    |--------------------------------------------------------------------------
    |
    | Defaults applied when the corresponding command option is omitted.
    | Every option still overrides its config value per invocation, e.g.
    | `make:bitmask Network --namespace="Modules\Core\BitMasks"`.
    |
    */

    'generator' => [

        // Namespace of generated flag classes (--namespace).
        'namespace' => 'App\\BitMasks',

        // Directory generated files are written to (--path).
        // Relative paths are resolved from the application base path.
        'path' => 'app/BitMasks',

        // Output style: 'enum' (int-backed enum) or 'constants' (final class
        // of int constants) (--type).
        'type' => 'enum',

    ],

];
