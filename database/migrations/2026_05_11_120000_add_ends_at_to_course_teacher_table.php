<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_teacher', function (Blueprint $table) {
            $table->timestamp('ends_at')->nullable()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('course_teacher', function (Blueprint $table) {
            $table->dropColumn('ends_at');
        });
    }
};
