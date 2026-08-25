<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Workflows\QuarterlyReview;
use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Checkpoint;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Workflows\Workflow;
use Illuminate\Http\Request;

/**
 * The workflow screens.
 *
 * The point of these pages is not that a job ran. It is that you can watch a
 * step get skipped on the second pass, which is the only part of a durable
 * workflow that is hard to believe without seeing it.
 */
class WorkflowController extends Controller
{
    /**
     * Every step the demo workflow can take, in order.
     *
     * Declared rather than derived so the page can show what has not happened
     * yet, instead of only what has.
     *
     * @var array<int, array{key: string, label: string, detail: string, kind: string}>
     */
    public const PLAN = [
        ['key' => 'stale', 'label' => 'Find quiet deals', 'detail' => 'Reads the pipeline', 'kind' => 'read'],
        ['key' => 'pipeline', 'label' => 'Total the pipeline', 'detail' => 'Runs beside the one on its left', 'kind' => 'read'],
        ['key' => 'summarise', 'label' => 'Summarise the risk', 'detail' => 'Asks the agent', 'kind' => 'agent'],
        ['key' => 'draft', 'label' => 'Draft the emails', 'detail' => 'Asks the agent, once per deal', 'kind' => 'agent'],
        ['key' => 'send-outreach', 'label' => 'Wait for a human', 'detail' => 'The run parks and the worker exits', 'kind' => 'pause'],
        ['key' => 'send', 'label' => 'Send them', 'detail' => 'Irreversible, so it runs once', 'kind' => 'write'],
    ];

    public function index()
    {
        return $this->page('workflows.index', [
            'runs' => $this->runs(),
        ], 'Workflows');
    }

    public function show(string $run)
    {
        $model = $this->findRun($run);

        return $this->page('workflows.show', $this->detail($model), 'Workflow');
    }

    /**
     * The live view, polled by the page while a run is moving.
     */
    public function state(string $run)
    {
        $model = $this->findRun($run);
        $detail = $this->detail($model);

        return response()->json([
            'status' => $model->status->value,
            'steps' => $detail['steps'],
            'passes' => $detail['passes'],
            'events' => $detail['events'],
            'approval' => $detail['approval']?->only(['id', 'tool_name', 'arguments', 'reason']),
            'output' => $model->structured_output,
            'artifacts' => $detail['artifacts'],
            'finished' => $model->status->isTerminal(),
        ]);
    }

    public function start(Request $request)
    {
        $run = QuarterlyReview::start()
            ->name('Quarterly review')
            ->dispatch(['stale_after_days' => (int) $request->input('stale_after_days', 21)]);

        return redirect()->route('workflows.show', $run->id);
    }

    public function decide(Request $request, string $run)
    {
        $model = $this->findRun($run);

        abort_unless($model->status === RunStatus::AwaitingApproval, 409, 'Nothing is waiting.');

        Workflow::resume($model->id, [
            'approved' => $request->boolean('approved'),
            'reason' => $request->input('reason'),
        ]);

        return redirect()->route('workflows.show', $model->id);
    }

    /**
     * Everything the detail page and its poller both need.
     *
     * @return array<string, mixed>
     */
    protected function detail(Run $run): array
    {
        $state = $this->state_of($run);
        $completed = array_keys($state['steps'] ?? []);

        $steps = [];

        foreach (self::PLAN as $step) {
            $steps[] = [
                ...$step,
                'status' => $this->statusOf($run, $step, $completed, $state),
                'value' => $this->preview($state['steps'][$step['key']]['value'] ?? null),
            ];
        }

        return [
            'run' => $run,
            'steps' => $steps,
            'passes' => $this->passes($run),
            'events' => $this->events($run),
            'approval' => $run->approvals()->where('status', 'pending')->first(),
            'artifacts' => $run->artifacts()->get()
                ->map(fn ($a): array => ['id' => $a->id, 'name' => $a->name, 'bytes' => $a->size_bytes])
                ->all(),
            'emailCount' => \App\Models\Activity::query()
                ->where('kind', 'email')
                ->where('by_agent', true)
                ->where('summary', 'like', 'Quarterly review:%')
                ->count(),
            'agentSessions' => Session::query()
                ->whereNotNull('agent_class')
                ->get()
                ->filter(fn (Session $s): bool => ($s->metadata['workflow_run_id'] ?? null) === $run->id)
                ->values(),
        ];
    }

    /**
     * @param  array{key: string, kind: string}  $step
     * @param  array<int, string>  $completed
     * @param  array<string, mixed>  $state
     */
    protected function statusOf(Run $run, array $step, array $completed, array $state): string
    {
        if ($step['kind'] === 'pause') {
            if ($run->status === RunStatus::AwaitingApproval) {
                return 'waiting';
            }

            return isset($state['resume_input']) && $state['resume_input'] !== [] ? 'done' : 'pending';
        }

        if (in_array($step['key'], $completed, true)) {
            return 'done';
        }

        return $run->status === RunStatus::Running ? 'running' : 'pending';
    }

    /**
     * How many times the body has been entered, and what each pass skipped.
     *
     * This is the whole demonstration: pass two re-enters the same code and
     * replays the steps that pass one already finished.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function passes(Run $run): array
    {
        $passes = [];
        $index = -1;

        foreach ($run->events()->orderBy('sequence')->get() as $event) {
            if ($event->type === EventType::RunStarted) {
                $passes[] = ['ran' => [], 'replayed' => []];
                $index++;

                continue;
            }

            if ($event->type !== EventType::StepCompleted || $index < 0) {
                continue;
            }

            $bucket = ($event->payload['replayed'] ?? false) ? 'replayed' : 'ran';
            $passes[$index][$bucket][] = $event->payload['step'];
        }

        return array_values(array_filter(
            $passes,
            fn (array $p): bool => $p['ran'] !== [] || $p['replayed'] !== [],
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function events(Run $run): array
    {
        return $run->events()
            ->orderBy('sequence')
            ->get()
            ->map(fn ($e): array => [
                'sequence' => $e->sequence,
                'type' => $e->type === EventType::DriverEvent
                    ? (string) ($e->payload['driver_type'] ?? 'driver.event')
                    : $e->type->value,
                'step' => $e->payload['step'] ?? null,
                'replayed' => $e->payload['replayed'] ?? null,
                'at' => $e->occurred_at?->format('H:i:s'),
            ])
            ->all();
    }

    /**
     * The workflow's persisted state, which is where step results live.
     *
     * @return array<string, mixed>
     */
    protected function state_of(Run $run): array
    {
        $checkpoint = Checkpoint::query()
            ->where('session_id', $run->session_id)
            ->where('driver', 'workflow')
            ->latest('id')
            ->first();

        return (array) ($checkpoint?->payload ?? []);
    }

    protected function preview(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return mb_strimwidth($value, 0, 160, '…');
        }

        if (is_array($value)) {
            return count($value).' '.(count($value) === 1 ? 'item' : 'items');
        }

        return mb_strimwidth((string) json_encode($value), 0, 160, '…');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Run>
     */
    protected function runs()
    {
        return Run::query()
            ->whereIn('session_id', Session::query()->where('driver', 'workflow')->select('id'))
            ->latest('created_at')
            ->limit(20)
            ->get();
    }

    protected function findRun(string $run): Run
    {
        return Run::query()->with('session')->findOrFail($run);
    }
}
