<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The thing the agent is working towards.
 *
 * Publishing is the irreversible step the whole demo is built around: it is
 * what makes human approval worth having, and what makes an idempotent tool
 * more than a theoretical nicety.
 */
class Post extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'sources' => 'array',
        ];
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function publish(): void
    {
        $this->forceFill(['published_at' => now()])->save();
    }
}
