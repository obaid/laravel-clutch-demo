<?php

declare(strict_types=1);

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ChaosController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

// The CRM. Ordinary pages; the shell swaps only the main pane between them.
Route::get('/', [CrmController::class, 'pipeline'])->name('pipeline');
Route::get('/deals', [CrmController::class, 'deals'])->name('deals');
Route::get('/deals/{deal}', [CrmController::class, 'deal'])->name('deal');
Route::get('/companies', [CrmController::class, 'companies'])->name('companies');
Route::get('/contacts', [CrmController::class, 'contacts'])->name('contacts');
Route::get('/activity', [CrmController::class, 'activity'])->name('activity');

// The agent panel, which never reloads.
Route::get('/agent/thread', [AgentController::class, 'thread'])->name('agent.thread');
Route::post('/agent/reset', [AgentController::class, 'reset'])->name('agent.reset');
Route::post('/agent/messages', [AgentController::class, 'send'])->name('agent.send');
Route::post('/agent/approvals/{approval}', [AgentController::class, 'decide'])->name('agent.decide');

// The buttons that try to break a run.
Route::post('/chaos/{run}/kill', [ChaosController::class, 'killWorker'])->name('chaos.kill');
Route::post('/chaos/{run}/cancel', [ChaosController::class, 'cancel'])->name('chaos.cancel');
Route::post('/chaos/{run}/double-discount', [ChaosController::class, 'doubleDiscount'])->name('chaos.double');
Route::post('/chaos/reap', [ChaosController::class, 'reap'])->name('chaos.reap');

// The workflow screens. The point of these is watching a step get skipped.
Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
Route::post('/workflows', [WorkflowController::class, 'start'])->name('workflows.start');
Route::get('/workflows/{run}', [WorkflowController::class, 'show'])->name('workflows.show');
Route::get('/workflows/{run}/state', [WorkflowController::class, 'state'])->name('workflows.state');
Route::post('/workflows/{run}/decide', [WorkflowController::class, 'decide'])->name('workflows.decide');

// Workflow chaos. Same idea as above, but pressed from the workflow page.
Route::post('/workflows/{run}/chaos/kill', [ChaosController::class, 'wfKill'])->name('chaos.wf.kill');
Route::post('/workflows/{run}/chaos/reap', [ChaosController::class, 'wfReap'])->name('chaos.wf.reap');
Route::post('/workflows/{run}/chaos/retry', [ChaosController::class, 'wfRetry'])->name('chaos.wf.retry');
Route::post('/workflows/{run}/chaos/cancel', [ChaosController::class, 'wfCancel'])->name('chaos.wf.cancel');
