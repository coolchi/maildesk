import { describe, expect, it, vi } from 'vitest';
import {
    DOCS_EXPORT_FILENAME,
    buildDocsMarkdown,
    downloadText,
    htmlToMarkdown,
} from '@/lib/docsMarkdown';

const el = (html) => {
    const div = document.createElement('div');
    div.innerHTML = html;
    return div;
};

describe('htmlToMarkdown', () => {
    it('converts paragraphs with inline code, bold and link text', () => {
        const md = htmlToMarkdown(
            el(`<p>Send the   <code class="x">Authorization</code> header.
                The domain must be <strong>verified</strong>. See <button>Quickstart</button>.</p>`),
        );
        expect(md).toBe(
            'Send the `Authorization` header. The domain must be **verified**. See Quickstart.',
        );
    });

    it('converts tables to GFM and escapes pipes', () => {
        const md = htmlToMarkdown(
            el(`<div><table><thead><tr><th>Field</th><th>Type</th></tr></thead>
                <tbody><tr><td>to <span>required</span></td><td>string | array</td></tr></tbody></table></div>`),
        );
        expect(md).toBe(
            '| Field | Type |\n| --- | --- |\n| to required | string \\| array |',
        );
    });

    it('converts ordered and unordered lists', () => {
        expect(
            htmlToMarkdown(
                el('<ol><li>One</li><li>Two <code>x</code></li></ol>'),
            ),
        ).toBe('1. One\n2. Two `x`');
        expect(htmlToMarkdown(el('<ul><li>A</li><li>B</li></ul>'))).toBe(
            '- A\n- B',
        );
    });

    it('fences code blocks verbatim, labels them and skips copy buttons', () => {
        const md = htmlToMarkdown(
            el(`<div><div><p data-md-label>Request</p><button data-md-skip>Copy</button></div>
                <pre>curl -X POST https://x.test/api/v1/emails \\
  -d '{"a": 1}'</pre></div>`),
        );
        expect(md).toBe(
            '**Request**\n\n```\ncurl -X POST https://x.test/api/v1/emails \\\n  -d \'{"a": 1}\'\n```',
        );
        expect(md).not.toContain('Copy');
    });

    it('uses a longer fence when the code contains backticks', () => {
        const md = htmlToMarkdown(el('<pre>.update(`${ts}`)\n```</pre>'));
        expect(md.startsWith('````\n')).toBe(true);
        expect(md.endsWith('\n````')).toBe(true);
    });
});

describe('buildDocsMarkdown', () => {
    const md = buildDocsMarkdown({
        title: 'MailDesk API Documentation',
        intro: '- **Base URL:** `https://acme.test/api/v1`',
        generatedAt: new Date('2026-09-26T10:00:00Z'),
        groups: [
            {
                label: 'Getting started',
                sections: [{ title: 'Introduction', body: 'Hello.' }],
            },
            {
                label: 'Emails',
                sections: [
                    {
                        title: 'Send email',
                        method: 'POST',
                        url: 'https://acme.test/api/v1/emails',
                        body: '**Request**\n\n```\ncurl\n```',
                    },
                ],
            },
        ],
    });

    it('has a title, export date, intro and table of contents', () => {
        expect(
            md.startsWith(
                '# MailDesk API Documentation\n\n_Exported 2026-09-26._\n\n- **Base URL:**',
            ),
        ).toBe(true);
        expect(md).toContain(
            '## Contents\n\n- Getting started\n  - Introduction\n- Emails\n  - Send email',
        );
    });

    it('includes every group and section with the endpoint line', () => {
        expect(md).toContain(
            '## Getting started\n\n### Introduction\n\nHello.',
        );
        expect(md).toContain(
            '## Emails\n\n### Send email\n\n`POST https://acme.test/api/v1/emails`\n\n**Request**\n\n```\ncurl\n```\n',
        );
        expect(md).not.toMatch(/\n{3,}/);
        expect(md.endsWith('\n')).toBe(true);
    });
});

describe('downloadText', () => {
    it('downloads a markdown blob with the given filename', () => {
        const createObjectURL = vi.fn(() => 'blob:docs');
        URL.createObjectURL = createObjectURL;
        URL.revokeObjectURL = vi.fn();
        let clicked = null;
        const click = vi
            .spyOn(HTMLAnchorElement.prototype, 'click')
            .mockImplementation(function () {
                clicked = { href: this.href, download: this.download };
            });

        downloadText('# Docs\n', DOCS_EXPORT_FILENAME);

        expect(DOCS_EXPORT_FILENAME).toBe('maildesk-api-docs.md');
        expect(clicked).toEqual({
            href: 'blob:docs',
            download: 'maildesk-api-docs.md',
        });
        const blob = createObjectURL.mock.calls[0][0];
        expect(blob.type).toBe('text/markdown;charset=utf-8');
        click.mockRestore();
    });
});
