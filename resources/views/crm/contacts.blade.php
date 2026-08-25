@include('crm._head', ['title' => 'Contacts', 'meta' => $contacts->count().' total'])

<table class="w-full text-sm">
    <thead>
        <tr class="text-left text-[11px] uppercase tracking-wide ash border-b hair" style="background:var(--soft)">
            <th class="px-6 py-2 font-semibold">Name</th>
            <th class="px-3 py-2 font-semibold">Title</th>
            <th class="px-3 py-2 font-semibold">Company</th>
            <th class="px-6 py-2 font-semibold">Email</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($contacts as $c)
            <tr class="border-b hair hover:bg-[var(--soft)]">
                <td class="px-6 py-2.5 ink font-medium">{{ $c->name }}</td>
                <td class="px-3 py-2.5 mute">{{ $c->title }}</td>
                <td class="px-3 py-2.5 mute">{{ $c->company->name }}</td>
                <td class="px-6 py-2.5 mono text-[12px]" style="color:var(--blue)">{{ $c->email }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
