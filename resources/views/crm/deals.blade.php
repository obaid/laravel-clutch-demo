@include('crm._head', ['title' => 'Deals', 'meta' => $deals->count().' total'])

<table class="w-full text-sm">
    <thead>
        <tr class="text-left text-[11px] uppercase tracking-wide ash border-b hair" style="background:var(--soft)">
            <th class="px-6 py-2 font-semibold">Deal</th>
            <th class="px-3 py-2 font-semibold">Company</th>
            <th class="px-3 py-2 font-semibold">Stage</th>
            <th class="px-3 py-2 font-semibold">Owner</th>
            <th class="px-3 py-2 font-semibold text-right">Value</th>
            <th class="px-6 py-2 font-semibold text-right">Last touch</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($deals as $deal)
            <tr class="border-b hair hover:bg-[var(--soft)] cursor-pointer" onclick="location.href='{{ route('deal', $deal) }}'">
                <td class="px-6 py-2.5">
                    <a href="{{ route('deal', $deal) }}" class="mono text-[12px]" style="color:var(--blue)">{{ $deal->reference }}</a>
                    <div class="text-[12px] mute">{{ $deal->name }}</div>
                </td>
                <td class="px-3 py-2.5 ink">{{ $deal->company->name }}</td>
                <td class="px-3 py-2.5">
                    @include('crm._stage', ['stage' => $deal->stage])
                </td>
                <td class="px-3 py-2.5 mute">{{ $deal->owner }}</td>
                <td class="px-3 py-2.5 text-right ink font-semibold">
                    {{ $deal->netValue() }}
                    @if ($deal->discount_percent)
                        <div class="text-[10px] font-normal" style="color:var(--purple)">was {{ $deal->value() }}</div>
                    @endif
                </td>
                <td class="px-6 py-2.5 text-right text-[12px] {{ $deal->isStale() ? '' : 'ash' }}"
                    style="{{ $deal->isStale() ? 'color:var(--red)' : '' }}">
                    {{ $deal->last_touched_at?->diffForHumans() ?? '—' }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
