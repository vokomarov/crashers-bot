<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});

Route::post('/webhook/{token}', WebhookController::class)->name('webhook');

Route::fallback(function () {
    return response()->json(['status' => 'not found'], Response::HTTP_NOT_FOUND);
});
