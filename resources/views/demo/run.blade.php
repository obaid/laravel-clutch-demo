@extends('layout')
@section('title', 'Run · Laravel Clutch demo')

@section('content')
    @php $usage = $run->usage(); @endphp

    <div class="panel rounded-xl p-6 mb-6">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h1 class="text-lg font-semibold text-slate-100">{{ $run->session->name }}</h1>
                <p class="text-xs text-slate-500 mt-1">{{ $run->id }} · attempt {{ $run->attempt }}</p>
            </div>
            <span id="status" class="text-xs px-2.5 py-1 rounded bg-sky-400/10 text-sky-300">
                {{ $run->status->value }}
            </span>
        </div>

        <div class="grid grid-cols-4 gap-4 text-sm">
            <div>
                <div class="text-xs text-slate-500">Steps</div>
                <div id="steps" class="text-slate-200">{{ $usage->steps }}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500">Tool calls</div>
                <div id="tools" class="text-slate-200">{{ $usage->toolCalls }}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500">Tokens</div>
                <div id="tokens" class="text-slate-200">{{ number_format($usage->totalTokens()) }}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500">Cost</div>
                <div id="cost" class="text-slate-200">${{ number_format((float) $run->cost_usd, 4) }}</div>
            </div>
        </div>
    </div>

    {{-- Anything waiting on a human. --}}
    @foreach ($approvals->where('status.value', 'pending') as $approval)
        <div class="panel rounded-xl p-5 mb-6 border-l-2 border-l-amber-400">
            <div class="text-sm text-slate-200 mb-1">
                Waiting on you: <code class="accent">{{ $approval->tool_name }}</code>
            </div>
            <p class="text-xs text-slate-400 mb-3">{{ $approval->reason }}</p>
            <pre class="text-xs bg-slate-900 rounded p-3 mb-4 overflow-x-auto text-slate-300">{{ json_encode($approval->arguments, JSON_PRETTY_PRINT) }}</pre>

            <form method="POST" action="{{ route('demo.decide', $approval->id) }}" class="flex gap-2">
                @csrf
                <input name="reason" placeholder="Why? (optional)"
                       class="flex-1 rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm">
                <button name="decision" value="approve"
                        class="rounded-lg bg-teal-400 text-slate-900 px-4 py-2 text-sm font-semibold hover:bg-teal-300">Approve</button>
                <button name="decision" value="reject"
                        class="rounded-lg border border-slate-600 px-4 py-2 text-sm hover:border-rose-400 hover:text-rose-300">Reject</button>
            </form>
        </div>
    @endforeach

    {{-- The live event stream. This is the part that survives a reload. --}}
    <div class="panel rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between">
            <span class="text-xs uppercase tracking-wider text-slate-500">Event stream</span>
            <span class="text-xs text-slate-600">
                reload this page mid-run, it resumes from where you were
            </span>
        </div>
        <div id="events" class="p-5 space-y-1 text-xs max-h-96 overflow-y-auto"></div>
    </div>

    @if ($artifacts->isNotEmpty())
        <div class="panel rounded-xl p-5 mb-6">
            <div class="text-xs uppercase tracking-wider text-slate-500 mb-3">Artifacts</div>
            @foreach ($artifacts as $artifact)
                <a href="/api/clutch/artifacts/{{ $artifact->id }}"
                   class="flex items-center justify-between py-2 text-sm hover:text-teal-300">
                    <span>{{ $artifact->name }}</span>
                    <span class="text-xs text-slate-500">{{ number_format($artifact->size_bytes / 1024, 1) }} KB</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($post?->isPublished())
        <div class="panel rounded-xl p-5 mb-6 border-l-2 border-l-teal-400">
            <div class="text-sm text-slate-200">Published: {{ $post->title }}</div>
            <div class="text-xs text-slate-500 mt-1">
                The publish tool body ran <strong class="accent">{{ $post->publish_attempts }}</strong> time(s).
                However hard you try below, it stays at 1.
            </div>
        </div>
    @endif

    {{-- Try to break it. --}}
    <div class="panel rounded-xl p-5">
        <div class="text-xs uppercase tracking-wider text-slate-500 mb-1">Try to break it</div>
        <p class="text-xs text-slate-500 mb-4">Each of these is something that happens in production.</p>

        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('chaos.kill', $run->id) }}">@csrf
                <button class="rounded-lg border border-slate-700 px-3 py-2 text-xs hover:border-amber-400 hover:text-amber-300">
                    Kill the worker mid-run
                </button>
            </form>
            <form method="POST" action="{{ route('chaos.reap') }}">@csrf
                <button class="rounded-lg border border-slate-700 px-3 py-2 text-xs hover:border-teal-400 hover:text-teal-300">
                    Run the reaper
                </button>
            </form>
            <form method="POST" action="{{ route('chaos.double', $run->id) }}">@csrf
                <button class="rounded-lg border border-slate-700 px-3 py-2 text-xs hover:border-amber-400 hover:text-amber-300">
                    Publish twice more
                </button>
            </form>
            <form method="POST" action="{{ route('chaos.cancel', $run->id) }}">@csrf
                <button class="rounded-lg border border-slate-700 px-3 py-2 text-xs hover:border-rose-400 hover:text-rose-300">
                    Cancel
                </button>
            </form>
        </div>
    </div>

    <script>
    (function () {
        const runId = @json($run->id);
        const box = document.getElementById('events');

        // Where this browser got to last time. Reloading the page picks up here
        // rather than replaying from the beginning or losing what it missed.
        const key = 'clutch:cursor:' + runId;
        let cursor = Number(localStorage.getItem(key) || 0);
        const seen = new Set();

        const colour = {
            'run.created': 'text-slate-400', 'run.queued': 'text-slate-400',
            'run.started': 'text-sky-300', 'step.started': 'text-slate-500',
            'step.completed': 'text-slate-500', 'text.delta': 'text-slate-300',
            'tool.call.requested': 'text-amber-300', 'tool.call.completed': 'text-teal-300',
            'tool.call.failed': 'text-rose-300', 'approval.requested': 'text-amber-300',
            'approval.resolved': 'text-teal-300', 'artifact.created': 'text-teal-300',
            'run.awaiting_approval': 'text-amber-300', 'run.completed': 'text-teal-300',
            'run.failed': 'text-rose-300', 'run.cancelled': 'text-rose-300',
            'run.suspended': 'text-amber-300', 'tool.output.spilled': 'text-amber-300',
        };

        function summarise(e) {
            const p = e.payload || {};
            switch (e.type) {
                case 'text.delta': return p.delta;
                case 'tool.call.requested': return p.tool + ' ' + JSON.stringify(p.arguments || {}).slice(0, 90);
                case 'tool.call.completed': return p.tool + ' → ' + String(p.result ?? '').slice(0, 90);
                case 'tool.call.failed': return p.tool + ' ✗ ' + (p.error || '');
                case 'approval.requested': return 'waiting on ' + p.tool;
                case 'approval.resolved': return p.tool + ' → ' + p.status;
                case 'artifact.created': return p.name;
                case 'usage.updated': return 'tokens=' + (p.usage?.total_tokens ?? 0);
                case 'run.completed': return String(p.text ?? '').slice(0, 120);
                case 'run.failed': return p.message || '';
                case 'run.suspended': return 'parked at ' + (p.reason || 'a boundary') + ', will resume';
                default: return '';
            }
        }

        function render(e) {
            const id = e.run_id + ':' + e.sequence;
            if (seen.has(id)) return;      // delivery is at least once
            seen.add(id);

            const line = document.createElement('div');
            line.className = 'flex gap-3 ' + (colour[e.type] || 'text-slate-500');
            line.innerHTML =
                '<span class="text-slate-700 w-8 text-right shrink-0">' + e.sequence + '</span>' +
                '<span class="w-44 shrink-0">' + e.type + '</span>' +
                '<span class="flex-1 break-all">' + (summarise(e) || '').replace(/</g, '&lt;') + '</span>';
            box.appendChild(line);
            box.scrollTop = box.scrollHeight;

            cursor = e.sequence;
            localStorage.setItem(key, cursor);

            if (['run.completed', 'run.failed', 'run.cancelled', 'run.budget_exceeded'].includes(e.type)) {
                setTimeout(() => location.reload(), 900);
            }
        }

        function follow() {
            const source = new EventSource('/api/clutch/runs/' + runId + '/events?after=' + cursor);

            source.onmessage = (m) => {
                if (m.data === '[DONE]') { source.close(); return; }
                try { render(JSON.parse(m.data)); } catch (_) {}
            };

            // The server holds a connection for a bounded time, then asks the
            // client to come back with its cursor.
            source.addEventListener('timeout', () => { source.close(); follow(); });
            source.onerror = () => { source.close(); setTimeout(follow, 2000); };
        }

        follow();
    })();
    </script>
@endsection
