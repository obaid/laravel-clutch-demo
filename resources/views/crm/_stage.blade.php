@php
    $tone = match ($stage) {
        'won' => ['var(--green-soft)', 'var(--green)'],
        'lost' => ['var(--red-soft)', 'var(--red)'],
        'negotiation', 'proposal' => ['var(--blue-soft)', 'var(--blue)'],
        default => ['var(--soft)', 'var(--mute)'],
    };
@endphp
<span class="text-[11px] px-1.5 py-0.5 rounded" style="background:{{ $tone[0] }};color:{{ $tone[1] }}">{{ $stage }}</span>
