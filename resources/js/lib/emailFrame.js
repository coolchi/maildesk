// Helpers for showing email HTML inside a sandboxed <iframe srcdoc>.
//
// The frame's sandbox does NOT include "allow-scripts", so nothing inside the
// email can run. Because scripts can't run in the frame, the height is
// measured from the parent (allowed by "allow-same-origin") instead of by a
// script inside the email.

export const SANDBOX = 'allow-same-origin allow-popups allow-popups-to-escape-sandbox';

const BASE_STYLE = `
:root { color-scheme: light only; }
html, body { background: #ffffff !important; color: #111827; }
body { margin: 0; padding: 16px; font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; overflow-wrap: anywhere; }
img { max-width: 100%; height: auto; }
table { max-width: 100%; }
pre { white-space: pre-wrap; }
`;

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
