<?php

use App\Http\Controllers\Api\ManageDetrackJobs;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/lightspeed/sale-completed', [ManageDetrackJobs::class, 'handleSaleCompleted']);

//https://3248c5bc1129.ngrok-free.app/api/lightspeed/sale-completed
