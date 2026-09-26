// Helpers for showing email HTML inside a sandboxed <iframe srcdoc>.
//
// The frame's sandbox does NOT include "allow-scripts", so nothing inside the
// email can run. Because scripts can't run in the frame, the height is
// measured from the parent (allowed by "allow-same-origin") instead of by a
// script inside the email.

export const SANDBOX = 'allow-same-origin allow-popups allow-popups-to-escape-sandbox';

const BASE_STYLE = `
:root { color-scheme: light only; }
html, body {
  background: #ffffff !important;
  color: #111827;
  max-width: 100% !important;
  overflow-x: hidden !important;
  word-break: break-word;
}
body {
  margin: 0;
  padding: 12px;
  font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  overflow-wrap: anywhere;
  -webkit-text-size-adjust: 100%;
}
img, video, svg { max-width: 100% !important; height: auto !important; }
table { max-width: 100% !important; }
td, th { word-break: break-word; }
pre, code { white-space: pre-wrap; word-break: break-word; }
a { word-break: break-all; }
`;

/** Common reply/forward wrappers from Gmail, Apple Mail, Outlook, Yahoo, Proton. */
const QUOTE_SELECTOR = [
    '.gmail_quote',
    '.gmail_quote_container',
    '.gmail_extra',
    '.yahoo_quoted',
    '.protonmail_quote',
    '.moz-cite-prefix',
    '#divRplyFwdMsg',
    'blockquote[type="cite"]',
].join(', ');

const PLAIN_QUOTE_RE =
    /(?:^|\n)(?:On .{10,200} wrote:|-{2,}\s*Original Message\s*-{2,}|_{5,}|From:\s.+\nSent:\s)/i;

export function buildSrcdoc(html) {
    return [
        '<!doctype html><html><head><meta charset="utf-8">',
        '<meta name="color-scheme" content="light only">',
        "<meta http-equiv=\"Content-Security-Policy\" content=\"script-src 'none'; object-src 'none'; frame-src 'none'; form-action 'none'; base-uri 'none'\">",
        '<base target="_blank">',
        `<style>${BASE_STYLE}</style>`,
        '</head><body>',
        html ?? '',
        '</body></html>',
    ].join('');
}

// Makes every link open in a new tab without giving the new page access
// back to MailDesk. The server already does this; this is a second layer.
export function hardenLinks(doc) {
    doc?.querySelectorAll('a[href]').forEach((a) => {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
    });
}

export function contentHeight(doc) {
    if (!doc?.documentElement) return 0;
    const body = doc.body;

    return Math.ceil(
        Math.max(
            doc.documentElement.scrollHeight || 0,
            doc.documentElement.offsetHeight || 0,
            body?.scrollHeight || 0,
            body?.offsetHeight || 0,
        ),
    );
}

/**
 * Split a message so reply history can stay collapsed in the thread view.
 * Threads already list each message; nested quotes inside a body are noise.
 *
 * @returns {{ visible: string, quoted: string, hasQuote: boolean }}
 */
export function splitQuotedHtml(html) {
    if (html == null || String(html).trim() === '') {
        return { visible: html ?? '', quoted: '', hasQuote: false };
    }

    if (typeof DOMParser === 'undefined') {
        return { visible: html, quoted: '', hasQuote: false };
    }

    const doc = new DOMParser().parseFromString(
        `<div id="md-root">${html}</div>`,
        'text/html',
    );
    const root = doc.getElementById('md-root');
    if (!root) {
        return { visible: html, quoted: '', hasQuote: false };
    }

    const quoteEl = earliestQuoteElement(root);
    if (!quoteEl) {
        return { visible: html, quoted: '', hasQuote: false };
    }

    const start = expandQuoteStart(quoteEl);
    const quotedNodes = [];
    let node = start;
    while (node) {
        const next = node.nextSibling;
        quotedNodes.push(node);
        node.parentNode?.removeChild(node);
        node = next;
    }

    const visible = root.innerHTML;
    const quotedWrap = doc.createElement('div');
    quotedNodes.forEach((n) => quotedWrap.appendChild(n));
    const quoted = quotedWrap.innerHTML;

    if (!meaningfulText(visible) || !meaningfulText(quoted)) {
        return { visible: html, quoted: '', hasQuote: false };
    }

    return { visible, quoted, hasQuote: true };
}

/**
 * Collapse plain-text reply history at the first "On … wrote:" / Original Message.
 *
 * @returns {{ visible: string, quoted: string, hasQuote: boolean }}
 */
export function splitQuotedText(text) {
    if (text == null || String(text).trim() === '') {
        return { visible: text ?? '', quoted: '', hasQuote: false };
    }

    const match = String(text).match(PLAIN_QUOTE_RE);
    if (!match || match.index == null) {
        return { visible: text, quoted: '', hasQuote: false };
    }

    const at = match.index + (match[0].startsWith('\n') ? 1 : 0);
    if (at <= 0) {
        return { visible: text, quoted: '', hasQuote: false };
    }

    const visible = String(text).slice(0, at).replace(/\s+$/, '');
    const quoted = String(text).slice(at).replace(/^\s+/, '');

    if (!visible.trim() || !quoted.trim()) {
        return { visible: text, quoted: '', hasQuote: false };
    }

    return { visible, quoted, hasQuote: true };
}

function earliestQuoteElement(root) {
    const matches = [...root.querySelectorAll(QUOTE_SELECTOR)];

    // Plain "On … wrote:" markers (Gmail attr sometimes stripped by sanitizer).
    root.querySelectorAll('div, p, span, font').forEach((el) => {
        if (el.closest(QUOTE_SELECTOR)) {
            return;
        }
        if (/^on .{8,200} wrote:?$/i.test((el.textContent || '').trim())) {
            matches.push(el);
        }
    });

    if (matches.length === 0) {
        // Last-resort: a trailing bare <blockquote> after real content.
        const blocks = [...root.querySelectorAll(':scope > blockquote')];
        if (blocks.length === 1 && meaningfulText(root.innerHTML.replace(blocks[0].outerHTML, ''))) {
            matches.push(blocks[0]);
        }
    }

    if (matches.length === 0) {
        return null;
    }

    // Prefer the outermost / earliest quote so nested history stays one block.
    return matches.reduce((earliest, el) => {
        if (!earliest) {
            return el;
        }
        const pos = earliest.compareDocumentPosition(el);
        return pos & Node.DOCUMENT_POSITION_PRECEDING ? el : earliest;
    }, null);
}

/**
 * Include a preceding "On … wrote:" attr line, <hr>, or blank <br>s with the quote.
 */
function expandQuoteStart(quoteEl) {
    let start = quoteEl;

    while (start.previousSibling) {
        const prev = start.previousSibling;

        if (prev.nodeType === Node.TEXT_NODE) {
            if (!prev.textContent?.trim()) {
                start = prev;
                continue;
            }
            break;
        }

        if (prev.nodeType !== Node.ELEMENT_NODE) {
            break;
        }

        const el = prev;
        if (el.tagName === 'BR' || el.tagName === 'HR') {
            start = el;
            continue;
        }
        if (
            el.classList?.contains('gmail_attr') ||
            el.id === 'appendonsend' ||
            /^on .+ wrote:?$/i.test((el.textContent || '').trim())
        ) {
            start = el;
            continue;
        }
        break;
    }

    return start;
}

function meaningfulText(html) {
    if (!html) {
        return false;
    }
    const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
    return (doc.body?.textContent || '').replace(/\u00a0/g, ' ').trim().length > 0;
}
