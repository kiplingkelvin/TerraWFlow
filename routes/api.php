<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/webhook', [\App\Http\Controllers\WhatsAppWebhookController::class, 'verify']);
Route::post('/webhook', [\App\Http\Controllers\WhatsAppWebhookController::class, 'handle']);
Route::post('/webhook/validation', [\App\Http\Controllers\WhatsAppWebhookController::class, 'data_validation']);

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});