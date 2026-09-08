/*
 * A native <video> element as a Quill block embed.
 *
 * Quill's own "video" format is an <iframe> for YouTube-style embeds. An
 * uploaded lesson video needs the HTML5 element instead, so the browser
 * plays the file directly and byte-range seeking works through the
 * signed-URL redirect (CourseMediaController::show).
 *
 * Without a blot, Quill has no idea what a <video> node is and drops it on
 * paste — which is what happened: the upload landed in R2, the editor
 * showed nothing, and there was no error anywhere. Registering this makes
 * the tag a first-class embed both when inserted after an upload and when
 * a saved body is loaded back into the editor for editing.
 *
 * Kept as a separate module so a Node/jsdom check can exercise the exact
 * code the browser runs.
 */
export function registerNativeVideo(Quill) {
    const BlockEmbed = Quill.import('blots/block/embed');

    class NativeVideo extends BlockEmbed {
        static blotName = 'nativeVideo';
        static tagName = 'VIDEO';

        static create(value) {
            const node = super.create();
            node.setAttribute('src', typeof value === 'string' ? value : value.src);
            node.setAttribute('controls', '');
            // Fetch enough to show the first frame and the duration, not the
            // whole file, on a page that may carry several of these.
            node.setAttribute('preload', 'metadata');
            return node;
        }

        static value(node) {
            return node.getAttribute('src');
        }
    }

    Quill.register(NativeVideo, true);

    return NativeVideo;
}
