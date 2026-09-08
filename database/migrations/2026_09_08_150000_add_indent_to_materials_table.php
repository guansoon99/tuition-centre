<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Move right" on the materials tab: a small indent so related resources can
 * be grouped under the one above, the way the previous site did it. Purely
 * visual; order is still sort_order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedTinyInteger('indent')->default(0)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('indent');
        });
    }
};
