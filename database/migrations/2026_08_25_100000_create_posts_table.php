<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('topic');
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->json('sources')->nullable();

            // Null until a human approves the publish. The demo's whole point.
            $table->timestamp('published_at')->nullable();

            // How many times the publish tool actually fired. Stays at 1 no
            // matter how hard you try to make it run twice.
            $table->unsignedInteger('publish_attempts')->default(0);

            $table->string('session_id', 40)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
