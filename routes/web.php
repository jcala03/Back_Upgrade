<?php

use App\Http\Controllers\PublicCatalogController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The existing public bridge preserves REQUEST_URI. No API prefix, session,
// authentication or business mutation is involved in these crawler-visible GETs.
Route::get('/tienda/{slug}', [PublicCatalogController::class, 'show'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
Route::get('/sitemap.xml', [PublicCatalogController::class, 'sitemap'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);

Route::get('/', function () {
    return view('welcome');
});
