<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dateTime('due_date')->nullable()->after('target_date');
            $table->unsignedSmallInteger('max_file_size_mb')->nullable()->after('due_date');
            $table->unsignedSmallInteger('max_files')->nullable()->after('max_file_size_mb');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'max_file_size_mb', 'max_files']);
        });
    }
};
