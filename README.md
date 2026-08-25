# Laravel Clutch demo

A small Laravel app you can try to break, built on [Laravel Clutch](https://github.com/obaid/laravel-clutch).

An agent researches a topic, writes a blog post, and stops to ask before publishing it. That last step is the point: publishing is irreversible, so a human decides, and the run has to survive however long that takes.

## Why this rather than a chatbot

A demo that prompts an agent and prints the answer shows nothing, because [Laravel AI](https://laravel.com/docs/ai) already does that on its own. So every screen here is something that would go wrong in a naive build:

| What you do | What survives it |
|---|---|
| Start a run and close the tab | Work carries on in a queue worker |
| Reload the page mid-run | The stream resumes from your cursor, no gap, no repeat |
| Wait at the approval step | The worker exits. Nothing holds a connection open |
| Approve from another tab, or tomorrow | The run resumes in a different process |
| Press "kill the worker" | The reaper recovers it from its last checkpoint |
| Press "publish twice more" | The tool body still runs once |

## Running it

You need PHP 8.3+ and an Anthropic API key.

```bash
git clone https://github.com/obaid/laravel-clutch-demo
cd laravel-clutch-demo

composer install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate
```

Put your key in `.env`:

```env
ANTHROPIC_API_KEY=sk-ant-...
```

Then run the app and a worker, in two terminals:

```bash
php artisan serve
php artisan queue:work --queue=agents
```

Open <http://localhost:8000>, type a topic, and press Start.

The worker matters. Runs are queued, so nothing happens without one, which is itself the first thing the demo is showing you.

## What to try

**Close the tab while it works.** Come back to `/`. The run is still going, or finished without you.

**Reload mid-run.** The event list picks up where you were rather than replaying from the top or losing what it missed. Your cursor lives in `localStorage`; the events live in the database.

**Stop at the approval.** The agent reaches `publish_post` and parks. Check your worker: it is idle, not blocked. Now approve from `/approvals`, which is a different request that knows nothing about the one that started the run.

**Reject with a reason.** The agent gets your reason back as the tool result and reacts to it, rather than the run dying.

**Press "publish twice more."** It asks the ledger to publish the same post under two fresh tool-call IDs, which is exactly what a crash and retry produce. The counter on the post stays at 1.

**Press "kill the worker," then "run the reaper."** The first leaves the run claiming to be running with a stale heartbeat, which is the state a `SIGKILL` leaves behind. The second finds it, fails it, and queues a fresh attempt from the last checkpoint.

## How it is put together

```
app/Ai/Agents/ContentAgent.php   An ordinary Laravel AI agent
app/Ai/Tools/FetchUrl.php        Read only, never asks
app/Ai/Tools/SaveDraft.php       Reversible, writes an artifact
app/Ai/Tools/PublishPost.php     Irreversible, approvable, idempotent
app/Http/Controllers/            Start, watch, approve
app/Http/Controllers/ChaosController.php   The buttons that try to break it
```

`PublishPost` is where the interesting part lives. Three protections meet on one class, each guarding a different failure:

```php
class PublishPost implements Approvable, IdempotentTool, SensitiveTool, Tool
```

`Approvable` makes a human decide. `SensitiveTool` tells the policy engine it is irreversible. `IdempotentTool` keys the side effect so a retry cannot fire it twice.

The UI is deliberately thin, because Clutch already ships the endpoints it needs. The live stream is one `EventSource` against `/api/clutch/runs/{run}/events?after={cursor}`, and the approval buttons post to routes that came with the package.

## Tests

```bash
./vendor/bin/pest
```

The suite covers the whole pipeline, including the approval pause, resuming from a separate request, idempotent publishing, and recovery from a killed worker. It uses Laravel AI's fake gateway, so it exercises the real driver, coordinator, broker and ledger with only the model call faked. No API key needed to run them.

## A note on what this demo skips

There is no login, so `config/clutch.php` opens the package routes to `web` rather than `auth`. A real application keeps `auth` there, which is what scopes every run and artifact to the participant who owns it.

## Licence

MIT.
