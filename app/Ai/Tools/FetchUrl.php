<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Clutch\Laravel\Contracts\SensitiveTool;
use Clutch\Laravel\Enums\ToolSensitivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Reads a public web page.
 *
 * Deliberately returns everything it finds rather than trimming politely. Real
 * pages are enormous, and watching Clutch spill the result to an artifact is
 * more convincing than being told it would.
 */
class FetchUrl implements SensitiveTool, Tool
{
    public function description(): Stringable|string
    {
        return 'Fetch the readable text of a public web page.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('An absolute http(s) URL.')->required(),
        ];
    }

    /**
     * Reading a page changes nothing, so it never needs approval.
     */
    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::ReadOnly;
    }

    public function handle(Request $request): Stringable|string
    {
        $url = (string) $request['url'];

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return "Refused: [{$url}] is not an absolute http(s) URL.";
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'laravel-clutch-demo'])
                ->get($url);
        } catch (\Throwable $e) {
            return "Could not fetch {$url}: {$e->getMessage()}";
        }

        if ($response->failed()) {
            return "Fetching {$url} returned HTTP {$response->status()}.";
        }

        return $this->readableText($response->body());
    }

    /**
     * Strip a page down to something a model can read.
     */
    protected function readableText(string $html): string
    {
        $text = preg_replace('#<(script|style|nav|footer|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
