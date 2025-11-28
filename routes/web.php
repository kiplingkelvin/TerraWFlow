<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/webhook', [\App\Http\Controllers\WhatsAppWebhookController::class, 'verify']);
Route::post('/webhook', [\App\Http\Controllers\WhatsAppWebhookController::class, 'handle']);
Route::post('/webhook/data_validation', [\App\Http\Controllers\WhatsAppWebhookController::class, 'data_validation']);
