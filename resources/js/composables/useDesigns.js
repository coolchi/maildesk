import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const SAMPLE = '<p style="margin:0 0 12px;">Hi Maya,</p><p style="margin:0 0 12px;">Your note sits in this frame. Leave the design off and the same words go out as plain mail.</p><p style="margin:0;">— The team</p>';

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

export function useDesigns() {
    const page = usePage();
    const catalog = computed(() => page.props.designs?.catalog ?? []);
    const defaultKey = computed(() => page.props.designs?.default || '');
    const brand = computed(() => page.props.tenant?.current?.name || '');

    const find = (key) => catalog.value.find((design) => design.key === key) || null;

    /**
     * Frame written HTML. An empty key returns the HTML unchanged.
     * Full documents that are already framed are not nested again.
     */
    function apply(html, key, accent) {
        const body = html || '';
        const design = find(key);
        if (!design?.fragment || String(body).includes('data-md-design=')) {
            return body;
        }

        const color = accent || design.accent || '#18181b';

        return design.fragment
            .replaceAll('{{brand}}', escapeHtml(brand.value))
            .replaceAll('{{accent}}', color)
            .replaceAll('{{content}}', body);
    }

    function applyDocument(html, key, accent) {
        const design = find(key);
        if (!design) {
            return html || '';
        }

        const fragment = apply(html, key, accent);

        return `<!DOCTYPE html><html data-md-design="${design.key}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:${design.background};">${fragment}</body></html>`;
    }

    return {
        catalog,
        defaultKey,
        brand,
        sample: SAMPLE,
        find,
        apply,
        applyDocument,
    };
}
