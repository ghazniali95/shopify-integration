<?php

use Illuminate\Support\Facades\Route;
use ShopGPT\ShopifyIntegration\Http\Controllers\WebhookController;

Route::post('webhooks', [WebhookController::class, 'handle'])
    ->name('shopifyIntegration.webhooks');
