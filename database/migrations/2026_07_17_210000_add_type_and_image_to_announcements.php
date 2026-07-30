<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // 'text' | 'image' — governs whether body or image_path is used.
            $table->string('type', 20)->default('text')->after('body')->index();
            $table->string('image_path')->nullable()->after('type');
        });

        // body stays NOT NULL — the controller stores '' for image-type
        // announcements. Avoids needing doctrine/dbal for a column change
        // that works differently on SQLite vs MySQL.
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['type', 'image_path']);
        });
    }
};
