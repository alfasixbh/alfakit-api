<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('api_admin')->default(false);
        });

        Schema::create('api_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->text('body')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_private')->default(false);
            $table->string('secret_note')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('api_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->nullable();
            $table->string('body');
            $table->timestamps();
        });
    }
};
