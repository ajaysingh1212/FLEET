<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// routes/web.php
use App\Http\Controllers\MapController;

Route::get('/', [MapController::class, 'index']);
Route::get('/device/{imei}/latest', [MapController::class, 'latest']);
Route::get('/device/{imei}/history', [MapController::class, 'history']);
