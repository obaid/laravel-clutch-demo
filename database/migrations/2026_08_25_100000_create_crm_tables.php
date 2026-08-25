<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('industry')->nullable();
            $table->unsignedInteger('employees')->nullable();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('deals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('name');
            $table->integer('value_cents');
            $table->string('stage')->default('discovery');
            $table->string('owner')->nullable();
            $table->timestamp('last_touched_at')->nullable();

            // Set when a discount is approved. Irreversible, so it is the thing
            // worth guarding.
            $table->unsignedTinyInteger('discount_percent')->nullable();

            // Counts every time the discount tool body actually runs. The
            // ledger keeps this at one however many times it is delivered.
            $table->unsignedInteger('discount_attempts')->default(0);

            $table->timestamps();
        });

        // The timeline you see on a deal, and proof of what the agent did.
        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');            // note, email, stage, discount
            $table->string('summary');
            $table->text('body')->nullable();
            $table->boolean('by_agent')->default(false);
            $table->timestamps();
        });

        // One chat thread per Clutch session.
        Schema::create('threads', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id', 40)->unique();
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('deals');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('companies');
    }
};
