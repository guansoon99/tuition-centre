<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill any NULL labels first — belt-and-braces, since app-level
        // validation now enforces required.
        DB::table('contacts')->whereNull('label')->update(['label' => '(untitled)']);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite has no "MODIFY COLUMN". Rebuild the table with the new
            // constraint. Kept in raw SQL because Laravel's Schema::change()
            // would require doctrine/dbal.
            DB::statement('CREATE TABLE contacts__new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type VARCHAR(20) NOT NULL,
                value VARCHAR(100) NOT NULL,
                label VARCHAR(100) NOT NULL,
                sort_order INTEGER UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )');
            DB::statement('INSERT INTO contacts__new (id, type, value, label, sort_order, is_active, created_at, updated_at)
                SELECT id, type, value, label, sort_order, is_active, created_at, updated_at FROM contacts');
            DB::statement('DROP TABLE contacts');
            DB::statement('ALTER TABLE contacts__new RENAME TO contacts');
            DB::statement('CREATE INDEX contacts_type_index ON contacts(type)');
            DB::statement('CREATE INDEX contacts_sort_order_index ON contacts(sort_order)');
        } else {
            // MySQL / MariaDB / Postgres all accept a straight MODIFY.
            DB::statement('ALTER TABLE contacts MODIFY label VARCHAR(100) NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('CREATE TABLE contacts__new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type VARCHAR(20) NOT NULL,
                value VARCHAR(100) NOT NULL,
                label VARCHAR(100) NULL,
                sort_order INTEGER UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )');
            DB::statement('INSERT INTO contacts__new (id, type, value, label, sort_order, is_active, created_at, updated_at)
                SELECT id, type, value, label, sort_order, is_active, created_at, updated_at FROM contacts');
            DB::statement('DROP TABLE contacts');
            DB::statement('ALTER TABLE contacts__new RENAME TO contacts');
            DB::statement('CREATE INDEX contacts_type_index ON contacts(type)');
            DB::statement('CREATE INDEX contacts_sort_order_index ON contacts(sort_order)');
        } else {
            DB::statement('ALTER TABLE contacts MODIFY label VARCHAR(100) NULL');
        }
    }
};
