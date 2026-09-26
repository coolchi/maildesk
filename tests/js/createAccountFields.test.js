import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import CreateAccountFields from '@/Components/CreateAccountFields.vue';

// Mount the fields inside a parent that owns the v-model state, like AppLayout.
const mountForm = (baseDomain = 'maildesk.test') =>
    mount(
        defineComponent({
            setup() {
                const name = ref('');
                const subdomain = ref('');
                return () =>
                    h(CreateAccountFields, {
                        baseDomain,
                        name: name.value,
                        subdomain: subdomain.value,
                        'onUpdate:name': (v) => (name.value = v),
                        'onUpdate:subdomain': (v) => (subdomain.value = v),
                    });
            },
        }),
    );

describe('CreateAccountFields', () => {
    it('auto-fills the subdomain from the team name and previews the address', async () => {
        const wrapper = mountForm();
        await wrapper.find('[data-test="team-name"]').setValue('Harbor Labs!');

        expect(wrapper.find('[data-test="subdomain"]').element.value).toBe('harbor-labs');
        expect(wrapper.find('[data-test="address-preview"]').text()).toBe('harbor-labs.maildesk.test');
    });

    it('stops auto-filling after the subdomain is edited by hand', async () => {
        const wrapper = mountForm();
        await wrapper.find('[data-test="team-name"]').setValue('Harbor');
        await wrapper.find('[data-test="subdomain"]').setValue('custom-sub');
        await wrapper.find('[data-test="team-name"]').setValue('Harbor Labs Renamed');

        expect(wrapper.find('[data-test="subdomain"]').element.value).toBe('custom-sub');
        expect(wrapper.find('[data-test="address-preview"]').text()).toBe('custom-sub.maildesk.test');
        expect(wrapper.find('[data-test="team-name"]').element.value).toBe('Harbor Labs Renamed');
    });

    it('re-enables auto-fill when the subdomain is cleared', async () => {
        const wrapper = mountForm();
        await wrapper.find('[data-test="subdomain"]').setValue('mine');
        await wrapper.find('[data-test="subdomain"]').setValue('');
        await wrapper.find('[data-test="team-name"]').setValue('Nova Co');

        expect(wrapper.find('[data-test="subdomain"]').element.value).toBe('nova-co');
    });

    it('uses the base domain it is given rather than a hard-coded one', async () => {
        const wrapper = mountForm('example.org');
        await wrapper.find('[data-test="team-name"]').setValue('Acme');

        expect(wrapper.find('[data-test="address-preview"]').text()).toBe('acme.example.org');
    });

    it('shows server validation errors for the subdomain', () => {
        const wrapper = mount(CreateAccountFields, {
            props: { baseDomain: 'maildesk.test', errors: { subdomain: 'That subdomain is reserved. Choose another.' } },
        });

        expect(wrapper.text()).toContain('That subdomain is reserved. Choose another.');
    });
});
