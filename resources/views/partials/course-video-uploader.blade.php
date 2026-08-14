{{--
    Shared video uploader for the Quill editors (material form + admin course
    page). Both toolbars need identical behaviour, so it is defined once here
    rather than copied into each.

    Sends the file straight to R2 and only tells this server about it
    afterwards. A lesson video is the one upload in the app large enough that
    proxying it would hold a PHP worker for the whole transfer and hit
    Cloudflare's 100MB request ceiling.

    Falls back to the proxied endpoint on any transport failure, for the same
    reason student submissions do: a network that blocks the R2 endpoint would
    otherwise leave a teacher unable to upload at all.

    Expects: $course
--}}
@once
<style>
    /* Overlay sits on the editor while an upload runs. The editor stays
       visible underneath so the teacher can see where the media will land. */
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
    .cm-spinner {
        width: 2.25rem;
        height: 2.25rem;
        border: 3px solid rgb(203 213 225);
        border-top-color: rgb(15 23 42);
        border-radius: 9999px;
        animation: cm-spin 0.7s linear infinite;
    }
    @keyframes cm-spin { to { transform: rotate(360deg); } }

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

    /* Respect users who have asked for less motion — the bar still conveys
       progress, so the spin is decoration rather than information. */
    @media (prefers-reduced-motion: reduce) {
        .cm-spinner { animation-duration: 2.5s; }
    }
</style>
<script>
/*
 * Blocking overlay for the duration of an upload.
 *
 * Anchored to the editor's container rather than the page, so the teacher can
 * still see the lesson they are editing. Returns a handle with the only two
 * things a caller needs: report progress, and take it away again.
 */
window.courseMediaOverlay = function (editorEl, initialText) {
    const host = editorEl.closest('.overflow-hidden') || editorEl.parentElement;
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
            // 100% means the bytes are sent, not that we are done — the
            // register step still has to accept it. Say so rather than
            // appearing stuck at 100.
            label.textContent = pct >= 100 ? 'Processing…' : 'Uploading… ' + pct + '%';
        },
        done() {
            el.remove();
            host.style.position = prevPosition;
        },
    };
};

window.uploadCourseVideo = (function () {
    const CSRF = () => document.querySelector('meta[name=csrf-token]').content;

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF(),
            },
            body: JSON.stringify(body),
        }).then(async (res) => {
            if (res.ok) return res.json();

            // 4xx is a decision about this file — retrying elsewhere would
            // only get the same answer. 5xx and network errors are not.
            if (res.status >= 400 && res.status < 500) {
                const data = await res.json().catch(() => ({}));
                const msg = data.message
                    || (data.errors && Object.values(data.errors)[0][0])
                    || 'That video was rejected.';
                throw Object.assign(new Error(msg), { rejected: true });
            }
            throw new Error('presign/register failed: ' + res.status);
        });
    }

    // XHR rather than fetch: only XHR reports upload progress, and a teacher
    // sending a few hundred MB needs to see that something is happening.
    function put(signed, file, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', signed.url);
            Object.entries(signed.headers || {}).forEach(([k, v]) => {
                if (/^(host|content-length)$/i.test(k)) return;   // browser-controlled
                xhr.setRequestHeader(k, Array.isArray(v) ? v[0] : v);
            });
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable && onProgress) {
                    onProgress(Math.round((e.loaded / e.total) * 100));
                }
            };
            xhr.onload = () => (xhr.status >= 200 && xhr.status < 300)
                ? resolve()
                : reject(new Error('PUT returned ' + xhr.status));
            xhr.onerror = () => reject(new Error('network error during PUT'));
            xhr.send(file);
        });
    }

    function proxied(urls, file, onProgress) {
        const form = new FormData();
        form.append('video', file);
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', urls.upload);
            xhr.setRequestHeader('X-CSRF-TOKEN', CSRF());
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable && onProgress) {
                    onProgress(Math.round((e.loaded / e.total) * 100));
                }
            };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(JSON.parse(xhr.responseText).url);
                } else {
                    reject(new Error('Upload failed (' + xhr.status + ')'));
                }
            };
            xhr.onerror = () => reject(new Error('network error'));
            xhr.send(form);
        });
    }

    return async function uploadCourseVideo(urls, file, onProgress) {
        // Checked before anything is sent, so an oversized file is refused
        // immediately rather than after a long doomed upload.
        if (file.size > urls.maxMb * 1024 * 1024) {
            const mb = (file.size / 1048576).toFixed(0);
            throw new Error(
                `That video is ${mb} MB — the limit is ${urls.maxMb} MB. ` +
                `Try exporting at a lower resolution or bitrate.`
            );
        }

        try {
            const signed = await post(urls.presign, {
                size: file.size,
                content_type: file.type,
            });
            await put(signed, file, onProgress);
            const done = await post(urls.register, { name: signed.name });

            return done.url;
        } catch (err) {
            if (err && err.rejected) throw err;

            // Transport failure, not a verdict — the R2 endpoint may be
            // blocked on this network. Send it through the server instead.
            if (onProgress) onProgress(0);

            return proxied(urls, file, onProgress);
        }
    };
})();
</script>
@endonce
