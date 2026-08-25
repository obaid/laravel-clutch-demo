<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Post;
use Clutch\Laravel\Artifacts\Artifact;
use Clutch\Laravel\Contracts\SensitiveTool;
use Clutch\Laravel\Enums\ToolSensitivity;
use Clutch\Laravel\Runtime\RunContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Saves the drafted post and attaches it to the run as an artifact.
 *
 * Writing a draft is reversible, so it runs without asking. The artifact is
 * what survives: it is downloadable long after the run is over, through a route
 * Clutch already authorizes.
 */
class SaveDraft implements SensitiveTool, Tool
{
    public function description(): Stringable|string
    {
        return 'Save the drafted post. Call this once the draft is finished.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The post title.')->required(),
            'body' => $schema->string()->description('The post body, in markdown.')->required(),
            'sources' => $schema->array()
                ->items($schema->string())
                ->description('URLs the draft drew on.'),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::Reversible;
    }

    public function handle(Request $request): Stringable|string
    {
        $context = RunContext::current();

        $post = Post::query()->where('session_id', $context?->sessionId())->firstOrFail();

        $post->forceFill([
            'title' => (string) $request['title'],
            'body' => (string) $request['body'],
            'sources' => (array) ($request['sources'] ?? []),
        ])->save();

        // Attach the draft to the run so it outlives the conversation.
        $context?->artifacts()->add(
            Artifact::fromContents(
                "# {$request['title']}\n\n{$request['body']}",
                "drafts/post-{$post->id}.md",
            )
                ->name("Draft: {$request['title']}")
                ->mimeType('text/markdown')
                ->metadata(['post_id' => $post->id])
        );

        return "Draft saved as post {$post->id}. It is not published yet.";
    }
}
