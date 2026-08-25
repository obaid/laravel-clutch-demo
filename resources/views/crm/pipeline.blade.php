@include('crm._head', ['title' => 'Pipeline', 'meta' => '$'.number_format($total / 100).' open'])

<div class="p-4 grid grid-cols-3 gap-3 xl:grid-cols-6">
    @foreach ($stages as $stage => $deals)
        <div>
            <div class="flex items-baseline justify-between px-1 pb-2">
                <span class="text-[11px] font-bold uppercase tracking-wide ink">{{ $stage }}</span>
                <span class="text-[11px] ash">{{ $deals->count() }}</span>
            </div>

            <div class="space-y-2">
                @foreach ($deals as $deal)
                    <a href="{{ route('deal', $deal) }}" class="card block p-2.5 hover:border-[var(--primary)] transition-colors">
                        <div class="mono text-[10px] ash">{{ $deal->reference }}</div>
                        <div class="text-[13px] font-semibold ink leading-snug mt-0.5">{{ $deal->company->name }}</div>
                        <div class="text-[11px] mute truncate">{{ $deal->name }}</div>
                        <div class="flex items-baseline justify-between mt-1.5">
                            <span class="text-[13px] font-semibold" style="color:{{ $deal->discount_percent ? 'var(--purple)' : 'var(--ink)' }}">
                                {{ $deal->netValue() }}
                            </span>
                            @if ($deal->isStale())
                                <span class="text-[10px] px-1 rounded" style="background:var(--red-soft);color:var(--red)">stale</span>
                            @endif
                        </div>
                        @if ($deal->discount_percent)
                            <div class="text-[10px] mt-1" style="color:var(--purple)">{{ $deal->discount_percent }}% off</div>
                        @endif
                    </a>
                @endforeach

                @if ($deals->isEmpty())
                    <div class="text-[11px] ash px-1">—</div>
                @endif
            </div>
        </div>
    @endforeach
</div>
