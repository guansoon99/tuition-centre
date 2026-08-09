<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two render modes per event: 'pill' (default — small bar in the day cell)
 * and 'background' (tints the whole day cell, matches how auto-fetched
 * public holidays render). Existing rows stay as pills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('display_style', 20)->default('pill')->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('display_style');
        });
    }
};
