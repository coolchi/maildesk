import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import EmailFrame from '@/Components/EmailFrame.vue';
import { SANDBOX, buildSrcdoc, contentHeight, hardenLinks } from '@/lib/emailFrame';

const html = '<p style="color:#808080">Unblock your Cloud Agents</p><a href="https://cursor.com">Raise limit</a>';

describe('EmailFrame', () => {
    it('renders the email in a sandboxed iframe, not inline with prose', () => {
        const wrapper = mount(EmailFrame, { props: { html } });
        const frame = wrapper.find('iframe');

        expect(frame.exists()).toBe(true);
        expect(wrapper.find('.prose').exists()).toBe(false);
        expect(frame.classes().some((c) => c.startsWith('prose'))).toBe(false);
        // The email HTML is only inside srcdoc, never injected into the page.
        expect(wrapper.element.innerHTML).not.toContain('<p style');
        expect(frame.attributes('srcdoc')).toContain('Unblock your Cloud Agents');
    });

    it('disables scripts in the sandbox', () => {
        const frame = mount(EmailFrame, { props: { html } }).find('iframe');
        const sandbox = frame.attributes('sandbox').split(/\s+/);

        expect(sandbox).not.toContain('allow-scripts');
        expect(sandbox).not.toContain('allow-top-navigation');
        expect(sandbox).toContain('allow-popups');
        expect(frame.attributes('sandbox')).toBe(SANDBOX);
        expect(buildSrcdoc(html)).toContain("script-src 'none'");
    });

    it('uses a fixed white, light-scheme background', () => {
        const doc = buildSrcdoc(html);
        const frame = mount(EmailFrame, { props: { html } }).find('iframe');

        expect(doc).toContain('color-scheme: light only');
        expect(doc).toContain('<meta name="color-scheme" content="light only">');
        expect(doc).toMatch(/background: #ffffff/);
        expect(frame.classes()).toContain('bg-white');
        expect(frame.attributes('style')).toContain('color-scheme: light');
    });

    it('opens links in a new tab', () => {
        expect(buildSrcdoc(html)).toContain('<base target="_blank">');

        const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
        hardenLinks(doc);
        const link = doc.querySelector('a');

        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener noreferrer');
    });

    it('auto-sizes to the content height', async () => {
        const wrapper = mount(EmailFrame, { props: { html, minHeight: 80 }, attachTo: document.body });
        const iframe = wrapper.find('iframe').element;

        // jsdom does no layout, so fake the measured document height.
        const fakeDoc = {
            documentElement: { scrollHeight: 640, offsetHeight: 600 },
            body: { scrollHeight: 612, offsetHeight: 600 },
            querySelectorAll: () => [],
        };
        Object.defineProperty(iframe, 'contentDocument', { value: fakeDoc, configurable: true });
        Object.defineProperty(iframe, 'contentWindow', { value: {}, configurable: true });

        await wrapper.find('iframe').trigger('load');
        await nextTick();
        expect(iframe.style.height).toBe('640px');

        fakeDoc.documentElement.scrollHeight = 900;
        wrapper.vm.resize();
        await nextTick();
        expect(iframe.style.height).toBe('900px');

        wrapper.unmount();
    });

    it('never shrinks below the minimum height', () => {
        expect(contentHeight(null)).toBe(0);
        const wrapper = mount(EmailFrame, { props: { html: '', minHeight: 120 } });
        expect(wrapper.find('iframe').element.style.height).toBe('120px');
    });
});
