@include('crm._head', ['title' => 'Workflows', 'meta' => $runs->count().' runs'])

<div class="px-6 py-5">
    <div class="rounded border hair p-5 mb-6" style="background:var(--card)">
        <h2 class="font-semibold ink mb-1">The quarterly review</h2>
        <p class="text-[13px] body max-w-2xl leading-relaxed">
            Six steps: find the deals that have gone quiet, total the pipeline, ask the agent
            what is at risk, draft an email for each one, stop for a human, then send.
            The interesting part is not that it runs. It is what happens on the second pass,
            after you approve: the same code runs again from the top and the work that already
            finished is skipped rather than repeated.
        </p>

        <form method="POST" action="{{ route('workflows.start') }}" class="mt-4 flex items-center gap-3">
            @csrf
            <label class="text-[13px] body">Treat a deal as quiet after</label>
            <input type="number" name="stale_after_days" value="21" min="1" max="365"
                   class="w-20 rounded border hair px-2 py-1 text-[13px] mono" style="background:var(--doc)">
            <span class="text-[13px] body">days</span>
            <button class="rounded px-3 py-1.5 text-[13px] font-semibold"
                    style="background:var(--primary);color:var(--dark)">Run it</button>
        </form>
    </div>

    @if ($runs->isEmpty())
        <p class="text-[13px] mute">No runs yet.</p>
    @else
        <table class="w-full text-sm rounded border hair overflow-hidden" style="background:var(--card)">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide ash border-b hair" style="background:var(--soft)">
                    <th class="px-4 py-2 font-semibold">Run</th>
                    <th class="px-3 py-2 font-semibold">Status</th>
                    <th class="px-3 py-2 font-semibold">Started</th>
                    <th class="px-4 py-2 font-semibold text-right">Cost</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($runs as $run)
                    <tr class="border-b hair hover:bg-[var(--soft)] cursor-pointer"
                        onclick="location.href='{{ route('workflows.show', $run->id) }}'">
                        <td class="px-4 py-2.5 mono text-[12px]" style="color:var(--blue)">{{ $run->id }}</td>
                        <td class="px-3 py-2.5">@include('workflows._status', ['status' => $run->status->value])</td>
                        <td class="px-3 py-2.5 text-[12px] mute">{{ $run->created_at?->diffForHumans() }}</td>
                        <td class="px-4 py-2.5 text-right mono text-[12px] mute">
                            {{ $run->cost_usd ? '$'.number_format((float) $run->cost_usd, 4) : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
