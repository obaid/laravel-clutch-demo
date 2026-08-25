<?php

declare(strict_types=1);

use App\Http\Controllers\ChaosController;
use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DemoController::class, 'index'])->name('demo.index');
Route::post('/runs', [DemoController::class, 'start'])->name('demo.start');
Route::get('/runs/{run}', [DemoController::class, 'run'])->name('demo.run');

Route::get('/approvals', [DemoController::class, 'approvals'])->name('demo.approvals');
Route::post('/approvals/{approval}', [DemoController::class, 'decide'])->name('demo.decide');

// The buttons that try to break a run.
Route::post('/chaos/{run}/kill', [ChaosController::class, 'killWorker'])->name('chaos.kill');
Route::post('/chaos/{run}/cancel', [ChaosController::class, 'cancel'])->name('chaos.cancel');
Route::post('/chaos/{run}/double-publish', [ChaosController::class, 'doublePublish'])->name('chaos.double');
Route::post('/chaos/reap', [ChaosController::class, 'reap'])->name('chaos.reap');
