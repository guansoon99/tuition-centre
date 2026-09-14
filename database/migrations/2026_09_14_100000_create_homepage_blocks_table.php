<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The editable content of the public homepage: one row per block, the
 * block's fields as JSON. Shapes and defaults live in
 * App\Support\HomepageContent; a missing row means "the defaults".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_blocks', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_blocks');
    }
};
