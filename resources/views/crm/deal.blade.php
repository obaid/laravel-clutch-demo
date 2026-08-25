@include('crm._head', ['title' => $deal->reference, 'meta' => $deal->company->name])

<div class="p-6 space-y-4">
    <div class="card p-5">
        <div class="flex items-start justify-between">
            <div>
                <h2 class="text-[20px] font-bold ink">{{ $deal->name }}</h2>
                <p class="text-sm mute mt-0.5">
                    {{ $deal->company->name }} · {{ $deal->company->industry }} · {{ number_format($deal->company->employees) }} staff
                </p>
            </div>
            <div class="text-right">
                <div class="text-[24px] font-bold ink">{{ $deal->netValue() }}</div>
                @if ($deal->discount_percent)
                    <div class="text-[12px]" style="color:var(--purple)">
                        {{ $deal->discount_percent }}% off {{ $deal->value() }}
                    </div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-4 gap-4 mt-5 pt-4 border-t hair text-sm">
            <div><div class="text-[11px] ash uppercase tracking-wide">Stage</div><div class="mt-1">@include('crm._stage', ['stage' => $deal->stage])</div></div>
            <div><div class="text-[11px] ash uppercase tracking-wide">Owner</div><div class="mt-1 ink">{{ $deal->owner }}</div></div>
            <div>
                <div class="text-[11px] ash uppercase tracking-wide">Contact</div>
                <div class="mt-1 ink">{{ $deal->contact?->name ?? '—' }}</div>
                <div class="text-[12px] mute">{{ $deal->contact?->email }}</div>
            </div>
            <div>
                <div class="text-[11px] ash uppercase tracking-wide">Last touch</div>
                <div class="mt-1 {{ $deal->isStale() ? '' : 'ink' }}" style="{{ $deal->isStale() ? 'color:var(--red)' : '' }}">
                    {{ $deal->last_touched_at?->diffForHumans() ?? '—' }}
                </div>
            </div>
        </div>
    </div>

    <div>
        <h3 class="text-[11px] font-bold uppercase tracking-wide ash mb-2">Activity</h3>
        <div class="card divide-y" style="border-color:var(--hair)">
            @forelse ($activities as $a)
                <div class="px-4 py-3 flex gap-3" style="border-color:var(--hair-soft)">
                    <span class="mono text-[10px] ash w-20 shrink-0 pt-0.5">{{ $a->created_at->format('d M') }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm ink">
                            {{ $a->summary }}
                            @if ($a->by_agent)
                                <span class="text-[10px] ml-1 px-1 rounded" style="background:var(--purple-soft);color:var(--purple)">agent</span>
                            @endif
                        </div>
                        @if ($a->body)<div class="text-[12px] mute mt-0.5 whitespace-pre-wrap">{{ $a->body }}</div>@endif
                    </div>
                    <span class="text-[10px] ash">{{ $a->kind }}</span>
                </div>
            @empty
                <div class="px-4 py-3 text-sm ash">Nothing logged yet.</div>
            @endforelse
        </div>
    </div>
</div>
