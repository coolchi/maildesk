import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import EmailFrame from '@/Components/EmailFrame.vue';
import {
    SANDBOX,
    buildSrcdoc,
    contentHeight,
    fitToWidth,
    hardenLinks,
    splitQuotedHtml,
    splitQuotedText,
} from '@/lib/emailFrame';

const html = '<p style="color:#808080">Unblock your Cloud Agents</p><a href="https://cursor.com">Raise limit</a>';

const gmailReply = `
<div dir="ltr">ok</div>
<br>
<div class="gmail_quote">
  <div class="gmail_attr">On Sat, Sep 26, 2026 at 11:02 AM someone wrote:</div>
  <blockquote class="gmail_quote">
    <div>testing</div>
    <div class="gmail_quote">
      <div class="gmail_attr">On earlier wrote:</div>
      <blockquote class="gmail_quote"><div>again</div></blockquote>
    </div>
  </blockquote>
</div>
`;

describe('EmailFrame', () => {
    it('renders the email in a sandboxed iframe, not inline with prose', () => {
        const wrapper = mount(EmailFrame, { props: { html } });
        const frame = wrapper.find('iframe');

        expect(frame.exists()).toBe(true);
        expect(wrapper.find('.prose').exists()).toBe(false);
        expect(frame.classes().some((c) => c.startsWith('prose'))).toBe(false);
        // Email HTML lives in the srcdoc attribute, not as page DOM nodes.
        expect(wrapper.find('p').exists()).toBe(false);
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

    it('shrinks a wide email to the frame and does not shrink again', () => {
        const body = {
            style: { zoom: '1' },
            scrollWidth: 600,
            offsetWidth: 600,
            dataset: {},
        };
        const doc = { body };

        fitToWidth(doc, 300);
        expect(Number(body.style.zoom)).toBeCloseTo(0.5, 2);
        expect(body.dataset.mdFitWidth).toBe('300');

        body.scrollWidth = 300;
        fitToWidth(doc, 302);
        expect(Number(body.style.zoom)).toBeCloseTo(0.5, 2);
    });

    it('never shrinks below the minimum height', () => {
        expect(contentHeight(null)).toBe(0);
        const wrapper = mount(EmailFrame, { props: { html: '', minHeight: 120 } });
        expect(wrapper.find('iframe').element.style.height).toBe('120px');
    });

    it('collapses nested reply history by default', () => {
        const wrapper = mount(EmailFrame, { props: { html: gmailReply } });
        const srcdoc = wrapper.find('iframe').attributes('srcdoc');

        expect(srcdoc).toContain('ok');
        expect(srcdoc).not.toContain('testing');
        expect(srcdoc).not.toContain('again');
        expect(wrapper.find('[data-testid="toggle-quoted-text"]').text()).toBe(
            'Show quoted text',
        );
    });

    it('expands quoted history when toggled', async () => {
        const wrapper = mount(EmailFrame, { props: { html: gmailReply } });

        await wrapper.find('[data-testid="toggle-quoted-text"]').trigger('click');
        await nextTick();

        const srcdoc = wrapper.find('iframe').attributes('srcdoc');
        expect(srcdoc).toContain('ok');
        expect(srcdoc).toContain('testing');
        expect(srcdoc).toContain('again');
        expect(wrapper.find('[data-testid="toggle-quoted-text"]').text()).toBe(
            'Hide quoted text',
        );
    });

    it('collapses the iframe height when hiding quoted text', async () => {
        const wrapper = mount(EmailFrame, {
            props: { html: gmailReply, minHeight: 80 },
            attachTo: document.body,
        });

        const measure = (scrollHeight) => {
            const iframe = wrapper.find('iframe').element;
            const fakeDoc = {
                documentElement: { scrollHeight, offsetHeight: scrollHeight },
                body: { scrollHeight, offsetHeight: scrollHeight },
                querySelectorAll: () => [],
            };
            Object.defineProperty(iframe, 'contentDocument', {
                value: fakeDoc,
                configurable: true,
            });
            Object.defineProperty(iframe, 'contentWindow', {
                value: {},
                configurable: true,
            });
            return iframe;
        };

        let iframe = measure(640);
        await wrapper.find('iframe').trigger('load');
        await nextTick();
        expect(iframe.style.height).toBe('640px');

        await wrapper.find('[data-testid="toggle-quoted-text"]').trigger('click');
        await nextTick();
        iframe = measure(640);
        await wrapper.find('iframe').trigger('load');
        await nextTick();
        expect(iframe.style.height).toBe('640px');

        await wrapper.find('[data-testid="toggle-quoted-text"]').trigger('click');
        await nextTick();
        iframe = measure(120);
        await wrapper.find('iframe').trigger('load');
        await nextTick();
        expect(iframe.style.height).toBe('120px');

        wrapper.unmount();
    });

    it('can keep quotes expanded when collapseQuotes is false', () => {
        const wrapper = mount(EmailFrame, {
            props: { html: gmailReply, collapseQuotes: false },
        });

        expect(wrapper.find('[data-testid="toggle-quoted-text"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('iframe').attributes('srcdoc')).toContain('testing');
    });
});

describe('splitQuotedHtml', () => {
    it('splits gmail reply quotes from the new content', () => {
        const result = splitQuotedHtml(gmailReply);

        expect(result.hasQuote).toBe(true);
        expect(result.visible).toContain('ok');
        expect(result.visible).not.toContain('testing');
        expect(result.quoted).toContain('testing');
        expect(result.quoted).toContain('again');
    });

    it('leaves messages without quotes alone', () => {
        const result = splitQuotedHtml('<p>Just hello</p>');

        expect(result).toEqual({
            visible: '<p>Just hello</p>',
            quoted: '',
            hasQuote: false,
        });
    });

    it('does not collapse when the whole body is a quote', () => {
        const onlyQuote =
            '<div class="gmail_quote"><blockquote>only history</blockquote></div>';
        const result = splitQuotedHtml(onlyQuote);

        expect(result.hasQuote).toBe(false);
        expect(result.visible).toBe(onlyQuote);
    });

    it('includes a preceding gmail_attr line in the quote', () => {
        const htmlWithAttr = `
            <div>new bit</div>
            <div class="gmail_attr">On Sat someone wrote:</div>
            <blockquote type="cite"><div>old bit</div></blockquote>
        `;
        const result = splitQuotedHtml(htmlWithAttr);

        expect(result.hasQuote).toBe(true);
        expect(result.visible).toContain('new bit');
        expect(result.visible).not.toContain('gmail_attr');
        expect(result.quoted).toContain('gmail_attr');
        expect(result.quoted).toContain('old bit');
    });
});

describe('splitQuotedText', () => {
    it('splits plain-text reply markers', () => {
        const text = [
            'ok',
            '',
            'On Sat, Sep 26, 2026 at 11:02 AM someone wrote:',
            '> testing',
            '>',
            '> On earlier wrote:',
            '> > again',
        ].join('\n');

        const result = splitQuotedText(text);

        expect(result.hasQuote).toBe(true);
        expect(result.visible).toBe('ok');
        expect(result.quoted).toContain('On Sat');
        expect(result.quoted).toContain('testing');
    });

    it('leaves plain text without markers alone', () => {
        expect(splitQuotedText('just a note')).toEqual({
            visible: 'just a note',
            quoted: '',
            hasQuote: false,
        });
    });
});
