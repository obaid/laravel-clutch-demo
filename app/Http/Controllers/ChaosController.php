<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Jobs\ReapAbandonedRuns;
use Clutch\Laravel\Models\Run;
use Illuminate\Http\Request;

/**
 * The buttons that try to break a run.
 *
 * Every action here simulates something that happens in production and would
 * quietly corrupt a naive implementation.
 */
class ChaosController extends Controller
{
    /**
     * Pretend the worker was killed mid-run.
     *
     * Leaves the run claiming to be running with a stale heartbeat, which is
     * exactly the state a SIGKILL leaves behind: no failure was ever recorded,
     * because there was nobody left to record it.
     */
    public function killWorker(string $runId)
    {
        $run = Run::query()->findOrFail($runId);

        $run->forceFill([
            'status' => RunStatus::Running,
            'started_at' => now()->subHour(),
            'heartbeat_at' => now()->subHour(),
        ])->save();

        return back()->with('chaos', 'Worker killed. The run still claims to be running with a stale heartbeat. Run the reaper to recover it.');
    }

    /**
     * Run the sweep that finds abandoned work.
     *
     * Fails the orphaned run and queues a fresh attempt from its last
     * checkpoint. The publish counter on the post is the thing to watch.
     */
    public function reap()
    {
        dispatch_sync(new ReapAbandonedRuns(staleAfterSeconds: 60, retry: true));

        return back()->with('chaos', 'Reaper swept. Any abandoned run was failed and retried from its checkpoint.');
    }

    /**
     * Ask a running run to stop.
     */
    public function cancel(Request $request, string $runId)
    {
        Run::query()->findOrFail($runId)->cancel(
            $request->string('reason')->toString() ?: 'Cancelled from the demo UI.'
        );

        return back()->with('chaos', 'Cancellation requested. No new step or tool will start.');
    }

    /**
     * Try to publish the same post twice, by hand.
     *
     * The ledger should refuse the second one without the tool body running,
     * which the counter on the post proves.
     */
    public function doublePublish(string $runId)
    {
        $run = Run::query()->findOrFail($runId);
        $post = Post::query()->where('session_id', $run->session_id)->firstOrFail();

        $before = $post->publish_attempts;

        $ledger = app(\Clutch\Laravel\Tools\ToolExecutionLedger::class);
        $tool = new \App\Ai\Tools\PublishPost;

        foreach (['manual_a', 'manual_b'] as $callId) {
            $invocation = new \Clutch\Laravel\Data\ToolInvocation(
                sessionId: $run->session_id,
                runId: $run->id,
                toolCallId: $callId,
                toolName: 'publish_post',
                arguments: ['post_id' => $post->id],
            );

            $ledger->guard($invocation, $tool, fn (): string => (string) $tool->handle(
                new \Laravel\Ai\Tools\Request(['post_id' => $post->id], $callId)
            ));
        }

        $after = $post->refresh()->publish_attempts;

        return back()->with('chaos', "Asked to publish twice more. The tool body ran ".($after - $before)." extra time(s), not 2.");
    }
}
