<?php

use Illuminate\Support\Facades\Route;
use ShopGPT\ShopifyIntegration\Http\Controllers\OAuthController;

Route::get('auth/begin', [OAuthController::class, 'begin'])
    ->name('shopifyIntegration.auth.begin');

Route::get('auth/callback', [OAuthController::class, 'callback'])
    ->name('shopifyIntegration.auth.callback');
