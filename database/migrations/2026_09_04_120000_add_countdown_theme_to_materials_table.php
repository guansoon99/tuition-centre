<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which colour a countdown card is painted.
 *
 * Stores the theme's key, not its CSS. Tailwind only emits classes it can
 * find as literal text when it builds, so a gradient assembled from a value
 * in the database would compile to nothing at all — the key maps to a fixed
 * string in Material::COUNTDOWN_THEMES, which is where the class names
 * actually live.
 *
 * Null means the default, so every existing countdown keeps the blue-purple
 * it has today without a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('countdown_theme', 20)->nullable()->after('target_date');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('countdown_theme');
        });
    }
};
