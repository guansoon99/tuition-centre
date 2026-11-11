/*
 * Quill on its own Vite entry — the rich-text editor for material bodies and
 * assignment descriptions.
 *
 * Replaces the jsdelivr copy. Same version (2.0.2), so this is a delivery
 * change, not an upgrade: the CDN tag was pinned and so is package.json.
 *
 * window.Quill is set because every caller is inline — `new Quill(container,
 * ...)` inside @push('scripts') and in the fetched modal fragments, neither
 * of which can import a module.
 *
 * The snow theme's CSS is imported here so it travels with the entry. That
 * puts it wherever @vite sits in the document, which must stay ABOVE the
 * hand-written .ql-* overrides in the views — those rely on coming later to
 * win the cascade at equal specificity.
 */
import Quill from 'quill';
import 'quill/dist/quill.snow.css';
import { registerNativeVideo } from './quill-native-video';

// Uploaded lesson videos are <video> embeds; see quill-native-video.js.
registerNativeVideo(Quill);

// Images also keep their style attribute, which is where a height the teacher
// typed at upload lives (Quill's own image keeps only alt, width and height).
// Without this the height would be dropped when the material is opened for
// editing again. The sanitiser limits what the style may say.
const BaseImage = Quill.import('formats/image');
class SizedImage extends BaseImage {
    static formats(domNode) {
        const formats = super.formats(domNode);
        if (domNode.hasAttribute('style')) formats.style = domNode.getAttribute('style');
        return formats;
    }

    format(name, value) {
        if (name !== 'style') {
            super.format(name, value);
        } else if (value) {
            this.domNode.setAttribute('style', value);
        } else {
            this.domNode.removeAttribute('style');
        }
    }
}
Quill.register(SizedImage, true);

window.Quill = Quill;
