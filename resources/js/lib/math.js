import katex from 'katex';
import 'katex/dist/katex.min.css';

// Rumus KaTeX (QZ-06): $...$ (sebaris) dan $$...$$ (blok) di dalam HTML aman
// dari server atau teks polos. Hanya bagian teks yang diubah; tag tidak disentuh.
const decode = (s) => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&');
const escapeHtml = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

function renderText(text) {
    return text.replace(/\$\$([^$]+?)\$\$|\$([^$\n]+?)\$/g, (m, block, inline) => {
        try {
            return katex.renderToString(decode(block ?? inline), { displayMode: !!block, throwOnError: false, strict: 'ignore', trust: false });
        } catch (e) {
            return m;
        }
    });
}

/** html: string HTML yang SUDAH dibersihkan server. */
export function mathHtml(html) {
    if (!html || !html.includes('$')) return html || '';
    return html.split(/(<[^>]+>)/g).map((part) => (part.startsWith('<') ? part : renderText(part))).join('');
}

/** text: teks polos (mis. opsi jawaban); di-escape dulu. */
export function mathText(text) {
    const safe = escapeHtml(String(text ?? ''));
    return safe.includes('$') ? renderText(safe) : safe;
}
