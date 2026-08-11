<?php

use App\Http\Controllers\DeploymentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/deployments', [DeploymentController::class, 'store'])->name('deployments.store');
Route::get('/deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
