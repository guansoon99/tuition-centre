<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\HomepageBlock;
use App\Models\SiteSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The editable words on the public homepage, block by block.
 *
 * Each block has a fixed shape (declared here, with the defaults the page
 * ships with) and one stored row that overrides it. The layout of the page
 * is not editable: only the content inside these shapes is, which is what
 * keeps "edit it on the page" a small feature rather than a page builder.
 *
 * Text fields may use {name} (the site name) and {year}; see fill().
 */
final class HomepageContent
{
    public const CACHE_KEY = 'public:homepage';

    /** Where an uploaded picture (a card's image, a reviewer's photo) lives on the public disk. */
    public const IMAGE_FOLDER = 'homepage/images';

    /** Icons a feature card may pick from: key => label shown in the editor. */
    public const ICONS = [
        'book' => 'Book',
        'notes' => 'Notes',
        'play' => 'Play',
        'chart' => 'Chart',
        'cap' => 'Graduation cap',
        'star' => 'Star',
    ];

    /**
     * @return array<string,array{label:string,defaults:array<string,mixed>}>
     */
    public static function blocks(): array
    {
        return [
            'features' => [
                'label' => 'Feature cards',
                'defaults' => [
                    // 'image' is an uploaded picture shown instead of the icon
                    // when set (a path under IMAGE_FOLDER).
                    'items' => [
                        ['icon' => 'book',  'image' => '', 'title' => 'Systematic Classes', 'text' => 'Well-structured lessons that build your understanding step by step.'],
                        ['icon' => 'notes', 'image' => '', 'title' => 'Complete Notes',     'text' => 'Easy to follow notes with key points and exam-focused content.'],
                        ['icon' => 'play',  'image' => '', 'title' => 'Recordings',         'text' => 'Missed a class? Rewatch anytime, anywhere.'],
                        ['icon' => 'chart', 'image' => '', 'title' => 'Exam Resources',     'text' => 'Past year questions, practices and useful materials.'],
                    ],
                ],
            ],
            'reviews' => [
                'label' => 'Student reviews',
                'defaults' => [
                    'eyebrow' => 'Student Reviews',
                    'heading' => 'What Our Students Say',
                    'subheading' => 'Real feedback from {name} students',
                    // 'image' is the reviewer's photo; without one the card
                    // shows the first letter of the name.
                    'items' => [
                        ['name' => 'Tan YT',       'stars' => 5, 'image' => '', 'quote' => 'Qin老师讲解很有耐心，准备的资料也很仔细。考试的时候都是看老师的note，只要专心听课，不用担心PA不及格！非常推荐Qin老师！'],
                        ['name' => 'Chinning Toh', 'stars' => 5, 'image' => '', 'quote' => '从Sem 1开始跟Qin老师，老师把复杂冗长的课程讲解到很简单！考试的时候完全靠Qin老师的note，很多题都会回答，而且对我真的很nice！'],
                        ['name' => 'Xiao Jun',     'stars' => 5, 'image' => '', 'quote' => 'Qin老师的笔记简单易懂，有时候还会特别拿一些好的例子。上课也不会只是单纯的照着note念，会带出一些实际例子，让我们更能掌握课题。非常感谢Qin老师！'],
                    ],
                ],
            ],
            'cta' => [
                'label' => '"Already a student" strip',
                'defaults' => [
                    'heading' => 'Already a student?',
                    'text' => 'Log in to access your classes, notes and more.',
                    'button' => 'Login to Oster',
                ],
            ],
            'footer' => [
                'label' => 'Footer',
                'defaults' => [
                    'copyright' => '© {year} {name}. Hak cipta terpelihara.',
                ],
            ],
        ];
    }

    public static function isBlock(string $block): bool
    {
        return array_key_exists($block, self::blocks());
    }

    /** @return array<string,string> block => label */
    public static function labels(): array
    {
        return array_map(fn ($b) => $b['label'], self::blocks());
    }

    /**
     * What the editor may send for a block. Sizes are generous for the
     * layout each field lands in, and lists are capped so a runaway
     * paste cannot make the sliders absurd.
     *
     * @return array<string,array<int,mixed>>
     */
    public static function rules(string $block): array
    {
        return match ($block) {
            'features' => [
                'items' => ['required', 'array', 'min:1', 'max:12'],
                'items.*.icon' => ['required', Rule::in(array_keys(self::ICONS))],
                // Only a file this editor uploaded (see uploadImage): a path
                // inside its own folder, nothing further up or elsewhere.
                'items.*.image' => ['nullable', 'string', 'max:255', 'regex:#^'.self::IMAGE_FOLDER.'/[A-Za-z0-9._-]+$#'],
                'items.*.title' => ['required', 'string', 'max:60'],
                'items.*.text' => ['required', 'string', 'max:200'],
            ],
            'reviews' => [
                'eyebrow' => ['nullable', 'string', 'max:60'],
                'heading' => ['required', 'string', 'max:80'],
                'subheading' => ['nullable', 'string', 'max:160'],
                'items' => ['present', 'array', 'max:30'],
                'items.*.name' => ['required', 'string', 'max:60'],
                'items.*.stars' => ['required', 'integer', 'min:1', 'max:5'],
                'items.*.image' => ['nullable', 'string', 'max:255', 'regex:#^'.self::IMAGE_FOLDER.'/[A-Za-z0-9._-]+$#'],
                'items.*.quote' => ['required', 'string', 'max:600'],
            ],
            'cta' => [
                'heading' => ['required', 'string', 'max:80'],
                'text' => ['nullable', 'string', 'max:200'],
                'button' => ['required', 'string', 'max:40'],
            ],
            'footer' => [
                'copyright' => ['required', 'string', 'max:160'],
                // The footer's neighbours, stored elsewhere but edited here
                // (see forEditor / saveFooterExtras).
                'address' => ['nullable', 'string', 'max:500'],
                'hours' => ['nullable', 'string', 'max:255'],
                'contacts' => ['nullable', 'array', 'max:10'],
                'contacts.*.id' => ['nullable', 'integer'],
                'contacts.*.type' => ['required', Rule::in(array_keys(Contact::TYPES))],
                'contacts.*.value' => ['required', 'string', 'max:100'],
                'contacts.*.label' => ['nullable', 'string', 'max:100'],
                'contacts.*.icon' => ['nullable', 'string', 'max:255', 'regex:#^'.self::IMAGE_FOLDER.'/[A-Za-z0-9._-]+$#'],
                'contacts.*.active' => ['nullable', 'boolean'],
            ],
            default => throw new \InvalidArgumentException("Unknown homepage block [{$block}]."),
        };
    }

    /**
     * Every block with its current content: the defaults, overridden by
     * whatever has been saved. Cached; save() clears it.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $stored = HomepageBlock::query()->get()->keyBy('key');
            $all = [];
            foreach (self::blocks() as $key => $block) {
                $all[$key] = array_merge($block['defaults'], $stored[$key]->data ?? []);
            }

            return $all;
        });
    }

    /** @return array<string,mixed> */
    public static function get(string $block): array
    {
        return self::all()[$block] ?? throw new \InvalidArgumentException("Unknown homepage block [{$block}].");
    }

    /**
     * Store a block. Only the keys the block's shape knows are kept, so a
     * stray field from the browser never lands in the row.
     *
     * @param  array<string,mixed>  $data
     */
    public static function save(string $block, array $data): void
    {
        $defaults = self::blocks()[$block]['defaults'] ?? throw new \InvalidArgumentException("Unknown homepage block [{$block}].");

        $clean = [];
        foreach ($defaults as $field => $default) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            if ($field === 'items') {
                $shape = array_keys($default[0] ?? []);
                // validated() can hand nested rows back out of index order
                // (it rebuilds them rule by rule); the index is the order.
                $items = $data['items'];
                ksort($items);
                $clean['items'] = array_values(array_map(function ($item) use ($shape) {
                    $row = [];
                    foreach ($shape as $k) {
                        $row[$k] = $k === 'stars' ? (int) ($item[$k] ?? 0) : (string) ($item[$k] ?? '');
                    }

                    return $row;
                }, $items));
            } else {
                $clean[$field] = (string) ($data[$field] ?? '');
            }
        }

        // An image a card no longer points at is deleted from the disk, so
        // replacing or removing one does not leave the old file behind.
        $before = array_filter(array_column(self::get($block)['items'] ?? [], 'image'));
        $after = array_filter(array_column($clean['items'] ?? [], 'image'));
        foreach (array_diff($before, $after) as $orphan) {
            PublicFile::forget($orphan);
        }

        HomepageBlock::updateOrCreate(['key' => $block], ['data' => $clean]);
        self::forgetCache();
    }

    /**
     * What the on-page editor works with: every block, plus the footer's
     * neighbours that live elsewhere -- the address and hours in
     * SiteSettings, the contact buttons as Contact rows -- so the footer
     * is edited as one thing. Inactive contacts are included, switched off.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function forEditor(): array
    {
        $all = self::all();
        $settings = SiteSettings::current();

        // The editor previews a card's image and a reviewer's photo; the
        // URL is worked out here and never stored.
        foreach (['features', 'reviews'] as $block) {
            $all[$block]['items'] = array_map(function (array $item) {
                $item['image'] = (string) ($item['image'] ?? '');
                $item['image_url'] = $item['image'] !== '' ? (string) PublicFile::url($item['image']) : '';

                return $item;
            }, $all[$block]['items']);
        }

        $all['footer']['address'] = (string) $settings->contact_address;
        $all['footer']['hours'] = (string) $settings->contact_hours;
        $all['footer']['contacts'] = Contact::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Contact $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'value' => $c->value,
                'label' => (string) $c->label,
                'icon' => (string) $c->icon_path,
                'icon_url' => (string) ($c->icon_url ?? ''),
                'active' => (bool) $c->is_active,
            ])
            ->values()
            ->all();

        return $all;
    }

    /**
     * The footer's neighbours, saved alongside the block: address and hours
     * into SiteSettings, the contact list synced onto the Contact rows --
     * rows with an id are updated, rows without are created, rows left out
     * are deleted, and the order given is the order shown.
     *
     * @param  array<string,mixed>  $data
     */
    public static function saveFooterExtras(array $data): void
    {
        DB::transaction(function () use ($data) {
            // Only what was sent is touched: a copyright-only save leaves
            // the address, hours and contacts exactly as they were.
            $settings = [];
            if (array_key_exists('address', $data)) {
                $settings['contact_address'] = $data['address'];
            }
            if (array_key_exists('hours', $data)) {
                $settings['contact_hours'] = $data['hours'];
            }
            if ($settings !== []) {
                SiteSettings::row()->update($settings);
            }

            if (! array_key_exists('contacts', $data)) {
                return;
            }

            // validated() rebuilds nested rows rule by rule, so a new contact
            // (no id) can come back after the ones that have one; the index
            // is the order the admin arranged.
            $contacts = $data['contacts'] ?? [];
            ksort($contacts);

            // Icons no contact points at afterwards are deleted from the disk.
            $iconsBefore = Contact::query()->whereNotNull('icon_path')->pluck('icon_path')->all();

            $keep = [];
            foreach (array_values($contacts) as $i => $c) {
                $attrs = [
                    'type' => $c['type'],
                    'value' => $c['value'],
                    'label' => (string) ($c['label'] ?? ''),
                    'icon_path' => ($c['icon'] ?? '') !== '' ? $c['icon'] : null,
                    'sort_order' => $i + 1,
                    'is_active' => (bool) ($c['active'] ?? true),
                ];
                $row = ! empty($c['id']) ? Contact::find($c['id']) : null;
                if ($row) {
                    $row->update($attrs);
                } else {
                    $row = Contact::create($attrs);
                }
                $keep[] = $row->id;
            }
            Contact::query()->whereNotIn('id', $keep)->delete();

            $iconsAfter = Contact::query()->whereNotNull('icon_path')->pluck('icon_path')->all();
            foreach (array_diff($iconsBefore, $iconsAfter) as $orphan) {
                PublicFile::forget($orphan);
            }
        });

        SiteSettings::forgetCache();
        Cache::forget('public:contacts');
    }

    /** Replace the {name} and {year} placeholders a text field may carry. */
    public static function fill(?string $text): string
    {
        return strtr((string) $text, [
            '{name}' => SiteSettings::current()->displayName(),
            '{year}' => date('Y'),
        ]);
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
