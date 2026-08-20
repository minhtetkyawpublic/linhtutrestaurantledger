<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('/offline.html', static fn () => response(
    file_get_contents(public_path('offline.html')),
    200,
    ['Content-Type' => 'text/html; charset=UTF-8'],
));

Route::any('/{sensitive}', static fn () => abort(404))
    ->where('sensitive', '^(?:\.env(?:\..*)?|\.git(?:/.*)?|composer\.(?:json|lock)|artisan|package(?:-lock)?\.json|phpunit(?:\.[A-Za-z0-9_-]+)?\.xml|(?:vite|vitest|eslint|postcss|tailwind)\.config\.(?:js|ts|mjs|cjs)|(?:README|DEPLOYMENT_RECORD|LOCAL_TEST_COMMANDS)(?:\.[A-Za-z0-9_-]+)?|app(?:/.*)?|bootstrap(?:/.*)?|config(?:/.*)?|database(?:/.*)?|reference_docs(?:/.*)?|resources(?:/.*)?|routes(?:/.*)?|scripts(?:/.*)?|storage(?:/.*)?|tests(?:/.*)?|vendor(?:/.*)?)$');

Route::view('/{any}', 'welcome')
    ->where('any', '^(?!api).*$')
    ->name('spa.fallback');
