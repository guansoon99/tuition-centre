<?php

use App\Models\Contact;
use App\Support\HomepageContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The homepage footer used to show the Contact rows managed under
 * Settings > Contact. It now has contacts of its own, stored with the
 * homepage content and edited on the page, while the Contact rows keep
 * feeding the floating buttons on the logged-in pages. So the footer does
 * not go blank on deploy, the active rows are copied in once, in order,
 * icons included, unless the footer already has contacts of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('homepage_blocks') || ! Schema::hasTable('contacts')) {
            return;
        }

        HomepageContent::forgetCache();
        $footer = HomepageContent::get('footer');
        if (! empty($footer['contacts'])) {
            return;
        }

        $contacts = Contact::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Contact $c) => [
                'type' => $c->type,
                'value' => $c->value,
                'label' => (string) $c->label,
                'icon' => (string) $c->icon_path,
            ])
            ->values()
            ->all();

        if ($contacts === []) {
            return;
        }

        HomepageContent::save('footer', ['contacts' => $contacts]);
    }

    public function down(): void
    {
        // The copied list stays; nothing to undo safely.
    }
};
