@include('crm._head', ['title' => 'Activity', 'meta' => 'Everything, newest first'])

<div class="p-6">
    <div class="card divide-y" style="border-color:var(--hair)">
        @forelse ($activities as $a)
            <div class="px-4 py-3 flex gap-3" style="border-color:var(--hair-soft)">
                <span class="mono text-[10px] ash w-24 shrink-0 pt-0.5">{{ $a->created_at->format('d M H:i') }}</span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm ink">
                        {{ $a->summary }}
                        @if ($a->by_agent)
                            <span class="text-[10px] ml-1 px-1 rounded" style="background:var(--purple-soft);color:var(--purple)">agent</span>
                        @endif
                    </div>
                    @if ($a->body)<div class="text-[12px] mute mt-0.5">{{ \Illuminate\Support\Str::limit($a->body, 160) }}</div>@endif
                </div>
                @if ($a->deal)
                    <a href="{{ route('deal', $a->deal) }}" class="mono text-[11px] shrink-0" style="color:var(--blue)">{{ $a->deal->reference }}</a>
                @endif
            </div>
        @empty
            <div class="px-4 py-3 text-sm ash">Nothing yet.</div>
        @endforelse
    </div>
</div>
