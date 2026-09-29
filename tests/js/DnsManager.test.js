import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import DnsManager from '@/Components/DnsManager.vue';

const { page, routerPost } = vi.hoisted(() => ({
    page: { props: { flash: {} } },
    routerPost: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: {
        post: routerPost,
        put: vi.fn(),
        delete: vi.fn(),
    },
    useForm: (initial) => ({
        ...initial,
        errors: {},
        processing: false,
        post: vi.fn(),
        reset: vi.fn(),
    }),
}));

const domain = { id: 7, name: 'acme.test' };

const dns = {
    connection: { zone_name: 'acme.test' },
    records: [
        {
            key: 'inbound_mx',
            type: 'MX',
            host: 'acme.test',
            state: 'missing',
            value: 'inbound-smtp.eu-west-1.amazonaws.com',
        },
    ],
    live: [],
    auto_publish: { skipped_inbound_mx: false },
};

const mountManager = () => mount(DnsManager, { props: { domain, dns } });

describe('DnsManager receiving confirmation', () => {
    beforeEach(() => {
        routerPost.mockReset();
        page.props.flash = {};
        globalThis.route = (name, id) => `/route/${name}/${id}`;
    });

    it('shows the Enable receiving button when the inbound MX is missing', () => {
        const wrapper = mountManager();

        expect(wrapper.get('[data-testid="enable-receiving"]').text()).toContain('Enable receiving');
        expect(wrapper.find('[data-testid="receiving-confirm"]').exists()).toBe(false);
    });

    it('opens the confirm dialog when the receiving confirmation flash is set', () => {
        page.props.flash = {
            receiving_confirmation: {
                required: true,
                existing_mx: 'aspmx.l.google.com',
                message: 'acme.test already has MX records (aspmx.l.google.com).',
            },
        };

        const wrapper = mountManager();
        const dialog = wrapper.get('[data-testid="receiving-confirm"]');

        expect(wrapper.find('[data-testid="enable-receiving"]').exists()).toBe(false);
        expect(dialog.text()).toContain('acme.test already has MX records (aspmx.l.google.com).');
        expect(dialog.text()).toContain('Confirm & enable');
    });

    it('re-submits with confirmation so receiving is forced on', async () => {
        page.props.flash = {
            receiving_confirmation: {
                required: true,
                existing_mx: 'aspmx.l.google.com',
                message: 'acme.test already has MX records (aspmx.l.google.com).',
            },
        };

        const wrapper = mountManager();
        await wrapper.get('[data-testid="confirm-receiving"]').trigger('click');

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost).toHaveBeenCalledWith(
            '/route/domains.dns.enable-receiving/7',
            { confirm: true },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
