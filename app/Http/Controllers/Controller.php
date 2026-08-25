<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //

    /**
     * Render a pane, or the whole shell when the browser asked for a page.
     *
     * The agent panel lives outside the swapped region, so a navigation that
     * only replaces the main pane never interrupts a run in progress.
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
