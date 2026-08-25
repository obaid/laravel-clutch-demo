<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;

/**
 * The CRM itself. Ordinary pages, no agent involved.
 *
 * Each renders a fragment when asked for one, so the shell can swap the main
 * pane without reloading the page and losing the agent panel.
 */
class CrmController extends Controller
{
    public function pipeline()
    {
        return $this->page('crm.pipeline', [
            'stages' => collect(Deal::STAGES)->mapWithKeys(fn (string $stage): array => [
                $stage => Deal::query()->with(['company', 'contact'])->where('stage', $stage)->get(),
            ]),
            'total' => Deal::query()->whereNotIn('stage', ['lost'])->sum('value_cents'),
        ], 'Pipeline');
    }

    public function deals()
    {
        return $this->page('crm.deals', [
            'deals' => Deal::query()->with(['company', 'contact'])->orderByDesc('value_cents')->get(),
        ], 'Deals');
    }

    public function deal(Deal $deal)
    {
        $deal->load(['company', 'contact']);

        return $this->page('crm.deal', [
            'deal' => $deal,
            'activities' => $deal->activities()->limit(30)->get(),
        ], $deal->reference);
    }

    public function companies()
    {
        return $this->page('crm.companies', [
            'companies' => Company::query()->withCount(['contacts', 'deals'])->orderBy('name')->get(),
        ], 'Companies');
    }

    public function contacts()
    {
        return $this->page('crm.contacts', [
            'contacts' => Contact::query()->with('company')->orderBy('name')->get(),
        ], 'Contacts');
    }

    public function activity()
    {
        return $this->page('crm.activity', [
            'activities' => Activity::query()->with('deal')->latest('id')->limit(60)->get(),
        ], 'Activity');
    }

    /**
     * Render a full page, or just the main pane when the shell asks for it.
     *
     * The agent panel lives outside the swapped region, so navigating never
     * interrupts a run it is streaming.
     *
     * @param  array<string, mixed>  $data
     */
    protected function page(string $view, array $data, string $title)
    {
        $data['title'] = $title;

        if (request()->headers->get('X-Pane') === 'main') {
            return response()->view($view, $data);
        }

        return view('shell', [...$data, 'pane' => $view]);
    }
}
