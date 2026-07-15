<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote Sections to pure containers and let each "resource" inside live
 * as a Material — including text blocks and countdown timers (previously
 * encoded as section types).
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite enforces enum() via a CHECK constraint; MySQL via the
        // ENUM type itself. We add 'text' and 'countdown' as valid values
        // by recreating the column as a plain string.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE materials MODIFY type VARCHAR(32) NOT NULL");
        } elseif (DB::getDriverName() === 'sqlite') {
            // SQLite can't drop a column constraint in place — temp column dance.
            DB::statement("ALTER TABLE materials ADD COLUMN type_new VARCHAR(32) DEFAULT 'pdf' NOT NULL");
            DB::statement("UPDATE materials SET type_new = type");
            DB::statement("ALTER TABLE materials DROP COLUMN type");
            DB::statement("ALTER TABLE materials RENAME COLUMN type_new TO type");
        }

        Schema::table('materials', function (Blueprint $table) {
            if (! Schema::hasColumn('materials', 'body')) {
                $table->longText('body')->nullable()->after('external_url');
            }
            if (! Schema::hasColumn('materials', 'target_date')) {
                $table->timestamp('target_date')->nullable()->after('body');
            }
        });

        // Migrate existing text/countdown sections into Materials.
        DB::table('sections')->where('type', 'text')->orderBy('id')->each(function ($section) {
            DB::table('materials')->insert([
                'section_id' => $section->id,
                'title' => 'Text',
                'type' => 'text',
                'body' => $section->description,
                'sort_order' => 0,
                'is_published' => $section->is_published,
                'published_at' => now(),
                'uploaded_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::table('sections')->where('type', 'countdown')->orderBy('id')->each(function ($section) {
            DB::table('materials')->insert([
                'section_id' => $section->id,
                'title' => $section->title.' countdown',
                'type' => 'countdown',
                'target_date' => $section->target_date,
                'sort_order' => 0,
                'is_published' => $section->is_published,
                'published_at' => now(),
                'uploaded_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Every section is now just a container.
        DB::table('sections')->update([
            'type' => 'standard',
            'description' => null,
            'target_date' => null,
        ]);
    }

    public function down(): void
    {
        // Best-effort rollback: pull text/countdown materials back onto
        // their parent sections. Multi-resource sections cannot be cleanly
        // reverted, so we leave the rest behind.
        $text = DB::table('materials')->where('type', 'text')->get();
        foreach ($text as $m) {
            DB::table('sections')
                ->where('id', $m->section_id)
                ->update(['type' => 'text', 'description' => $m->body]);
        }
        DB::table('materials')->where('type', 'text')->delete();

        $countdown = DB::table('materials')->where('type', 'countdown')->get();
        foreach ($countdown as $m) {
            DB::table('sections')
                ->where('id', $m->section_id)
                ->update(['type' => 'countdown', 'target_date' => $m->target_date]);
        }
        DB::table('materials')->where('type', 'countdown')->delete();

        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn(['body', 'target_date']);
        });
    }
};
