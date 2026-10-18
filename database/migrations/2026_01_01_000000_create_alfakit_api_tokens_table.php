<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('panel');
            $table->morphs('tokenable');
            $table->nullableMorphs('tenant');
            $table->string('name');
            // SHA-256 of the secret; the plain-text token is shown once and never stored.
            $table->string('token', 64)->unique();
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['panel', 'tokenable_type', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_api_tokens');
    }
};
