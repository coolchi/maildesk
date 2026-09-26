// Turns the rendered API docs into a single Markdown document.
// The Docs page renders one section at a time; it walks every section,
// converts the rendered DOM with htmlToMarkdown() and hands the pieces to
// buildDocsMarkdown(), so the export always matches what the page shows.

export const DOCS_EXPORT_FILENAME = 'maildesk-api-docs.md';

const collapse = (text) => text.replace(/\s+/g, ' ');

const escapeCell = (text) => text.replace(/\|/g, '\\|').trim();

function inline(node) {
    let out = '';
    node.childNodes.forEach((child) => {
        if (child.nodeType === 3) {
            out += collapse(child.textContent);
            return;
        }
        if (child.nodeType !== 1 || child.hasAttribute('data-md-skip')) return;
        const tag = child.tagName.toLowerCase();
        const inner = inline(child);
        if (tag === 'code') {
            const text = collapse(child.textContent).trim();
            out += text.includes('`') ? `\`\` ${text} \`\`` : `\`${text}\``;
        } else if (tag === 'strong' || tag === 'b') {
            out += `**${inner.trim()}**`;
        } else if (tag === 'em' || tag === 'i') {
            out += `*${inner.trim()}*`;
        } else if (tag === 'br') {
            out += '  \n';
        } else {
            out += inner;
        }
    });
    return out;
}

function fence(text) {
    const longest = Math.max(
        2,
        ...(text.match(/`+/g) ?? []).map((m) => m.length),
    );
    const ticks = '`'.repeat(longest + 1);
    return `${ticks}\n${text.replace(/\n+$/, '')}\n${ticks}`;
}

function table(el) {
    const rows = [...el.querySelectorAll('tr')].map((tr) =>
        [...tr.children].map((cell) => escapeCell(inline(cell))),
    );
    if (!rows.length) return '';
    const width = Math.max(...rows.map((r) => r.length));
    const pad = (r) => [...r, ...Array(width - r.length).fill('')];
    const [head, ...body] = rows;
    return [
        `| ${pad(head).join(' | ')} |`,
        `| ${Array(width).fill('---').join(' | ')} |`,
        ...body.map((r) => `| ${pad(r).join(' | ')} |`),
    ].join('\n');
}

function list(el, ordered) {
    return [...el.children]
        .filter((li) => li.tagName.toLowerCase() === 'li')
        .map((li, i) => `${ordered ? `${i + 1}.` : '-'} ${inline(li).trim()}`)
        .join('\n');
}

function blocks(node) {
    const out = [];
    node.childNodes.forEach((child) => {
        if (child.nodeType === 3) {
            const text = collapse(child.textContent).trim();
            if (text) out.push(text);
            return;
        }
        if (child.nodeType !== 1 || child.hasAttribute('data-md-skip')) return;
        const tag = child.tagName.toLowerCase();
        const label = child.getAttribute('data-md-label');
        if (label !== null) {
            out.push(`**${collapse(child.textContent).trim()}**`);
        } else if (tag === 'pre') {
            out.push(fence(child.textContent));
        } else if (tag === 'table') {
            out.push(table(child));
        } else if (tag === 'ul' || tag === 'ol') {
            out.push(list(child, tag === 'ol'));
        } else if (/^h[1-6]$/.test(tag)) {
            out.push(
                `${'#'.repeat(Math.min(6, Number(tag[1]) + 2))} ${inline(child).trim()}`,
            );
        } else if (
            tag === 'p' ||
            tag === 'span' ||
            tag === 'code' ||
            tag === 'strong'
        ) {
            const text = inline(child).trim();
            if (text) out.push(text);
        } else {
            out.push(...blocks(child).filter(Boolean));
        }
    });
    return out.filter(Boolean);
}

/** Convert a rendered docs element to Markdown blocks joined by blank lines. */
export function htmlToMarkdown(element) {
    if (!element) return '';
    return blocks(element).join('\n\n');
}

/**
 * @param {object} doc
 * @param {string} doc.title
 * @param {string} [doc.intro]      Markdown shown under the title.
 * @param {Array<{label: string, sections: Array<{title: string, method?: string, url?: string, body: string}>}>} doc.groups
 * @param {Date} [doc.generatedAt]
 */
export function buildDocsMarkdown({
    title,
    intro = '',
    groups = [],
    generatedAt = new Date(),
}) {
    const lines = [
        `# ${title}`,
        '',
        `_Exported ${generatedAt.toISOString().slice(0, 10)}._`,
    ];
    if (intro.trim()) lines.push('', intro.trim());

    const toc = groups
        .map((g) =>
            [`- ${g.label}`, ...g.sections.map((s) => `  - ${s.title}`)].join(
                '\n',
            ),
        )
        .join('\n');
    if (toc) lines.push('', '## Contents', '', toc);

    groups.forEach((group) => {
        lines.push('', `## ${group.label}`);
        group.sections.forEach((section) => {
            lines.push('', `### ${section.title}`);
            if (section.method)
                lines.push('', `\`${section.method} ${section.url ?? ''}\``);
            if (section.body.trim()) lines.push('', section.body.trim());
        });
    });

    return `${lines.join('\n').replace(/\n{3,}/g, '\n\n')}\n`;
}

/** Trigger a browser download of text content. */
export function downloadText(
    content,
    filename,
    type = 'text/markdown;charset=utf-8',
) {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
}
