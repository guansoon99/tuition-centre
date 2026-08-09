<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `color` slug to events so calendar entries can be tagged with a
 * category color. Slugs (not raw hex) so the exact hex can be tuned in
 * one place (Event::COLORS) without a DB migration. Default 'blue' keeps
 * existing rows visually identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('color', 20)->default('blue')->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
