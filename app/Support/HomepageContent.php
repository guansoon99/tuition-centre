<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\HomepageBlock;
use App\Models\SiteSettings;
use Illuminate\Support\Facades\Cache;
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
                    // The homepage's own contact buttons (type, value, label,
                    // icon). Separate from the Contact rows managed under
                    // Settings > Contact, which feed the floating buttons on
                    // the logged-in pages.
                    'contacts' => [],
                ],
            ],
        ];
    }

    /**
     * The list fields each block carries, with the fields of one row. Lists
     * are cleaned row by row on save (only these fields, in this order).
     */
    private const LISTS = [
        'features' => ['items' => ['icon', 'image', 'title', 'text']],
        'reviews' => ['items' => ['name', 'stars', 'image', 'quote']],
        'footer' => ['contacts' => ['type', 'value', 'label', 'icon']],
    ];

    /** Row fields that hold an uploaded picture (deleted from the disk when dropped). */
    private const PICTURE_FIELDS = ['image', 'icon'];

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
                // Rich text from the editor: the limit is on the markup, and
                // an "empty" Quill document (<p><br></p>) does not count.
                'items.*.quote' => ['required', 'string', 'max:5000', function ($attribute, $value, $fail) {
                    if (trim(strip_tags((string) $value)) === '') {
                        $fail('The quote is required.');
                    }
                }],
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
                'contacts.*.type' => ['required', Rule::in(array_keys(Contact::TYPES))],
                'contacts.*.value' => ['required', 'string', 'max:100'],
                'contacts.*.label' => ['nullable', 'string', 'max:100'],
                'contacts.*.icon' => ['nullable', 'string', 'max:255', 'regex:#^'.self::IMAGE_FOLDER.'/[A-Za-z0-9._-]+$#'],
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
        $lists = self::LISTS[$block] ?? [];

        $clean = [];
        foreach (array_keys($defaults) as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            if (isset($lists[$field])) {
                // validated() can hand nested rows back out of index order
                // (it rebuilds them rule by rule); the index is the order.
                $rows = is_array($data[$field]) ? $data[$field] : [];
                ksort($rows);
                $clean[$field] = array_values(array_map(
                    fn ($row) => self::cleanRow($lists[$field], (array) $row),
                    $rows,
                ));
            } else {
                $clean[$field] = (string) ($data[$field] ?? '');
            }
        }

        // Fields the request did not send keep their stored value: a
        // copyright-only save must not wipe the footer's contacts.
        $stored = HomepageBlock::find($block)?->data ?? [];
        $merged = array_merge($stored, $clean);

        // A picture no row points at afterwards is deleted from the disk, so
        // replacing or removing one does not leave the old file behind.
        $before = self::pictures($block, $stored);
        $after = self::pictures($block, $merged);
        foreach (array_diff($before, $after) as $orphan) {
            PublicFile::forget($orphan);
        }

        HomepageBlock::updateOrCreate(['key' => $block], ['data' => $merged]);
        self::forgetCache();
    }

    /**
     * One list row, reduced to the fields the shape knows, each in its
     * proper type.
     *
     * @param  list<string>  $shape
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private static function cleanRow(array $shape, array $row): array
    {
        $clean = [];
        foreach ($shape as $k) {
            $clean[$k] = match ($k) {
                'stars' => (int) ($row[$k] ?? 0),
                // The editor's HTML, cleaned the same way material bodies
                // are: tags, classes and colours Quill emits, nothing else.
                'quote' => HtmlSanitizer::clean((string) ($row[$k] ?? '')),
                default => (string) ($row[$k] ?? ''),
            };
        }

        return $clean;
    }

    /**
     * Every uploaded picture the block's lists point at.
     *
     * @param  array<string,mixed>  $data
     * @return list<string>
     */
    private static function pictures(string $block, array $data): array
    {
        $paths = [];
        foreach (self::LISTS[$block] ?? [] as $field => $shape) {
            foreach ($data[$field] ?? [] as $row) {
                foreach (self::PICTURE_FIELDS as $k) {
                    if (($row[$k] ?? '') !== '') {
                        $paths[] = (string) $row[$k];
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * What the on-page editor works with: every block, with a preview URL
     * for each uploaded picture (worked out here, never stored), plus the
     * footer's address and hours, which live in SiteSettings but are edited
     * with the footer.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function forEditor(): array
    {
        $all = self::all();
        $settings = SiteSettings::current();

        foreach (self::LISTS as $block => $lists) {
            foreach ($lists as $field => $shape) {
                $all[$block][$field] = array_map(function (array $row) use ($shape) {
                    foreach (self::PICTURE_FIELDS as $k) {
                        if (in_array($k, $shape, true)) {
                            $row[$k] = (string) ($row[$k] ?? '');
                            $row[$k.'_url'] = $row[$k] !== '' ? (string) PublicFile::url($row[$k]) : '';
                        }
                    }

                    return $row;
                }, $all[$block][$field] ?? []);
            }
        }

        $all['footer']['address'] = (string) $settings->contact_address;
        $all['footer']['hours'] = (string) $settings->contact_hours;

        return $all;
    }

    /**
     * The footer's neighbours in SiteSettings, saved alongside the block:
     * only what was sent is touched, so a copyright-only save leaves the
     * address and hours as they were.
     *
     * @param  array<string,mixed>  $data
     */
    public static function saveFooterExtras(array $data): void
    {
        $settings = [];
        if (array_key_exists('address', $data)) {
            $settings['contact_address'] = $data['address'];
        }
        if (array_key_exists('hours', $data)) {
            $settings['contact_hours'] = $data['hours'];
        }
        if ($settings === []) {
            return;
        }

        SiteSettings::row()->update($settings);
        SiteSettings::forgetCache();
    }

    /**
     * A review quote as HTML for the page. Quotes saved from the rich-text
     * editor are already-cleaned HTML and pass through (cleaned again, cheaply,
     * in case a row predates the sanitiser); anything older is plain text and
     * is escaped with its line breaks kept.
     */
    public static function quoteHtml(?string $quote): string
    {
        $quote = (string) $quote;

        if (str_starts_with(ltrim($quote), '<')) {
            return HtmlSanitizer::clean($quote);
        }

        return nl2br(e($quote), false);
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
