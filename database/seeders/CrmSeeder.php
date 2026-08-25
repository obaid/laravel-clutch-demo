<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use Illuminate\Database\Seeder;

/**
 * A small pipeline with enough texture to ask real questions about.
 *
 * Fixed rather than random, so you can check whether the agent actually looked
 * something up or simply made it sound plausible.
 */
class CrmSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['Initech', 'initech.com', 'Manufacturing', 4200, [
                ['Marcus Webb', 'marcus@initech.com', 'VP Engineering'],
                ['Dana Cole', 'dana@initech.com', 'Procurement'],
            ], [
                ['IN-401', 'Initech Enterprise', 4_500_000, 'negotiation', 'Priya', 31],
            ]],
            ['Hooli', 'hooli.com', 'Technology', 12000, [
                ['Sarah Chen', 'sarah@hooli.com', 'Director of Data'],
            ], [
                ['HO-118', 'Hooli Platform Rollout', 12_000_000, 'proposal', 'Tom', 3],
                ['HO-092', 'Hooli Pilot', 800_000, 'won', 'Tom', 64],
            ]],
            ['Soylent', 'soylent.co', 'Food', 380, [
                ['Priya Nair', 'priya@soylent.co', 'Head of Ops'],
            ], [
                ['SO-233', 'Soylent Starter', 240_000, 'demo', 'Priya', 6],
            ]],
            ['Umbrella', 'umbrella.io', 'Healthcare', 900, [
                ['Tom Alvarez', 'tom@umbrella.io', 'CTO'],
            ], [
                ['UM-770', 'Umbrella Security Review', 3_100_000, 'discovery', 'Priya', 22],
            ]],
            ['Vandelay', 'vandelay.com', 'Logistics', 150, [
                ['Elaine Ross', 'elaine@vandelay.com', 'Founder'],
            ], [
                ['VA-015', 'Vandelay Team Plan', 90_000, 'lost', 'Tom', 88],
            ]],
        ];

        foreach ($rows as [$name, $domain, $industry, $employees, $people, $deals]) {
            $company = Company::query()->create(compact('name', 'domain', 'industry', 'employees'));

            $contacts = collect($people)->map(fn (array $p): Contact => Contact::query()->create([
                'company_id' => $company->id,
                'name' => $p[0],
                'email' => $p[1],
                'title' => $p[2],
            ]));

            foreach ($deals as [$reference, $dealName, $cents, $stage, $owner, $daysAgo]) {
                $deal = Deal::query()->create([
                    'company_id' => $company->id,
                    'contact_id' => $contacts->first()->id,
                    'reference' => $reference,
                    'name' => $dealName,
                    'value_cents' => $cents,
                    'stage' => $stage,
                    'owner' => $owner,
                    'last_touched_at' => now()->subDays($daysAgo),
                ]);

                Activity::query()->create([
                    'deal_id' => $deal->id,
                    'contact_id' => $deal->contact_id,
                    'kind' => 'note',
                    'summary' => 'Deal created',
                    'body' => "Opened at {$deal->value()} in the {$stage} stage.",
                    'created_at' => now()->subDays($daysAgo),
                    'updated_at' => now()->subDays($daysAgo),
                ]);
            }
        }

        // A little history on the one the demo nudges you towards.
        $initech = Deal::query()->where('reference', 'IN-401')->first();

        Activity::query()->create([
            'deal_id' => $initech->id,
            'contact_id' => $initech->contact_id,
            'kind' => 'email',
            'summary' => 'Sent pricing breakdown',
            'body' => 'Marcus asked for a per-seat breakdown before taking it to procurement.',
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);
    }
}
