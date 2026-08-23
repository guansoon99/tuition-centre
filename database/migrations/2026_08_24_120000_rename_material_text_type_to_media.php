<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * materials.type: 'text' → 'media'.
 *
 * The type picker has called this "Media" for a while; the stored value still
 * said 'text', so the code read one word and the database another. This brings
 * them together.
 *
 * Scoped to the materials table on purpose. `sections.type` and
 * `announcements.type` also use 'text' for unrelated things and must not move —
 * Section::TYPE_TEXT and Announcement::TYPE_TEXT still hold 'text'.
 *
 * 'page' is untouched. It is the same editor with "open on a separate page"
 * ticked, and it is stored as its own value already.
 *
 * The column is a plain VARCHAR(32) — the original enum was rebuilt away by
 * extend_materials_for_text_and_countdown — so there is no CHECK constraint to
 * widen first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('materials')->where('type', 'text')->update(['type' => 'media']);
    }

    public function down(): void
    {
        DB::table('materials')->where('type', 'media')->update(['type' => 'text']);
    }
};
