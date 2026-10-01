<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContributionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => ['ok' => true, 'app' => 'michango-ustawi']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/me/password', [AuthController::class, 'changePassword']);

    Route::get('/sync/pull', [SyncController::class, 'pull']);
    Route::post('/sync/push', [SyncController::class, 'push']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/summary/monthly', [DashboardController::class, 'monthly']);

    Route::apiResource('teachers', TeacherController::class);

    Route::get('/contributions/next-reference', [ContributionController::class, 'nextReference']);
    Route::post('/contributions/{contribution}/notify', [ContributionController::class, 'notify']);
    Route::apiResource('contributions', ContributionController::class);

    Route::post('/import/preview', [ImportController::class, 'preview']);
    Route::post('/import/commit', [ImportController::class, 'commit']);
    Route::post('/import/teachers/commit', [ImportController::class, 'commitTeachers']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::post('/settings/test-notification', [SettingsController::class, 'test']);
    Route::get('/notifications', [SettingsController::class, 'notifications']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
});
