{{--
    Feedback while something is uploading. Two mechanisms, because there are
    two kinds of upload in the app:

      1. XHR uploads (Quill media, student submissions) can report real
         progress, and get an overlay with a bar.
      2. Plain form POSTs cannot — the browser hands the request off and tells
         us nothing until the response. Those get a spinner in the submit
         button, plus protection against double-submitting.

    Included from the layout so every form gets (2) without being touched.
--}}
<style>
    .cm-spinner {
        display: inline-block;
        width: 2.25rem;
        height: 2.25rem;
        border: 3px solid rgb(203 213 225);
        border-top-color: rgb(15 23 42);
        border-radius: 9999px;
        animation: cm-spin 0.7s linear infinite;
    }
    /* Inline variant, sized to sit inside a button next to its label. */
    .cm-spinner--sm {
        width: 0.875rem;
        height: 0.875rem;
        border-width: 2px;
        border-color: rgb(255 255 255 / 0.45);
        border-top-color: #fff;
        vertical-align: -2px;
        margin-right: 0.5rem;
    }
    @keyframes cm-spin { to { transform: rotate(360deg); } }

    .cm-upload-overlay {
        position: absolute;
        inset: 0;
        z-index: 20;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        background: rgb(255 255 255 / 0.85);
        backdrop-filter: blur(1px);
    }
    .cm-upload-label { font-size: 0.875rem; color: rgb(15 23 42); }
    .cm-upload-bar {
        width: min(16rem, 70%);
        height: 0.375rem;
        border-radius: 9999px;
        background: rgb(226 232 240);
        overflow: hidden;
    }
    .cm-upload-bar > span {
        display: block;
        height: 100%;
        width: 0;
        background: rgb(15 23 42);
        transition: width 0.15s linear;
    }
    .cm-busy { cursor: progress; opacity: 0.85; }

    /* The bar still carries the information, so the spin is decoration. */
    @media (prefers-reduced-motion: reduce) {
        .cm-spinner { animation-duration: 2.5s; }
    }
</style>

<script>
/*
 * Blocking overlay for uploads that report progress.
 *
 * Anchored to the element being worked on rather than the page, so the user
 * can still see the context. Returns the only two things a caller needs:
 * report progress, and take it away again.
 */
window.courseMediaOverlay = function (anchorEl, initialText) {
    const host = anchorEl.closest('.overflow-hidden') || anchorEl.parentElement;
    const prevPosition = host.style.position;
    if (getComputedStyle(host).position === 'static') host.style.position = 'relative';

    const el = document.createElement('div');
    el.className = 'cm-upload-overlay';
    el.innerHTML =
        '<div class="cm-spinner"></div>' +
        '<p class="cm-upload-label"></p>' +
        '<div class="cm-upload-bar"><span></span></div>';
    host.appendChild(el);

    const label = el.querySelector('.cm-upload-label');
    const bar = el.querySelector('.cm-upload-bar > span');
    label.textContent = initialText || 'Uploading…';

    return {
        progress(pct) {
            bar.style.width = pct + '%';
            // 100% means the bytes are sent, not that we are finished — the
            // server still has to accept them. Saying "Processing" beats
            // appearing stuck at 100%.
            label.textContent = pct >= 100 ? 'Processing…' : 'Uploading… ' + pct + '%';
        },
        done() {
            el.remove();
            host.style.position = prevPosition;
        },
    };
};

/*
 * Spinner for ordinary form submissions.
 *
 * A form POST gives no progress events — the browser sends it and the page
 * sits there until the response. For a large PDF or a student import that
 * reads as a freeze, and an impatient second click submits twice.
 *
 * One delegated listener rather than per-form wiring, so forms added later
 * are covered without anyone remembering to.
 */
document.addEventListener('submit', function (event) {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) return;

    // Something else already took over — the student submission form, for
    // instance, cancels the POST and uploads via XHR with its own progress.
    // This listener bubbles, so those handlers have already run.
    if (event.defaultPrevented) return;
    if (form.hasAttribute('data-no-spinner')) return;

    // Second click while the first is still going.
    if (form.dataset.cmSubmitting === '1') {
        event.preventDefault();

        return;
    }
    form.dataset.cmSubmitting = '1';

    const button = form.querySelector('button[type=submit], input[type=submit]');
    if (! button) return;

    const hasFile = Array.from(form.querySelectorAll('input[type=file]'))
        .some((i) => i.files && i.files.length > 0);

    // Deliberately NOT disabled: a disabled control is omitted from the
    // submission, and the guard above already blocks a second click.
    button.classList.add('cm-busy');
    button.setAttribute('aria-busy', 'true');

    if (button.tagName === 'BUTTON') {
        button.innerHTML = '<span class="cm-spinner cm-spinner--sm"></span>'
            + (hasFile ? 'Uploading…' : 'Saving…');
    } else {
        button.value = hasFile ? 'Uploading…' : 'Saving…';
    }
});
</script>
