/*
 * SortableJS on its own Vite entry — drag-to-reorder for announcements,
 * banner slides, contacts and course sections.
 *
 * Replaces the jsdelivr copy at the same pinned version (1.15.2). No
 * stylesheet: Sortable ships none, and the drag classes are the app's own.
 *
 * window.Sortable is set because the callers are inline `Sortable.create(...)`
 * blocks in @push('scripts').
 */
import Sortable from 'sortablejs';

window.Sortable = Sortable;
