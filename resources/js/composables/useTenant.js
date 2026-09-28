import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

export function useTenant() {
    const page = usePage();

    const tenant = computed(() => page.props.tenant || {});
    const workspaces = computed(() => tenant.value.workspaces || []);
    const activeWorkspace = computed(() => tenant.value.current || workspaces.value[0] || null);
    const activeWorkspaceId = computed(() => activeWorkspace.value?.id ?? null);
    const hostLocked = computed(() => Boolean(tenant.value.host_locked));
    const canSend = computed(() => Boolean(tenant.value.can_send));
    const sendingFrom = computed(() => tenant.value.sending_from || []);
    // Non-secret summary of a platform SMTP provider (null otherwise); never has a password.
    const activeSmtp = computed(() => tenant.value.smtp || null);
    const activeProvider = computed(() => tenant.value.provider || null);
    const baseDomain = computed(() => tenant.value.base_domain || 'maildesk.ng');

    const activeProviderHealth = computed(() => {
        const provider = activeProvider.value;
        if (!provider) {
            return { ok: false, reason: 'orphaned', provider: null };
        }
        if (provider.status !== 'active') {
            return { ok: false, reason: 'disabled', provider };
        }
        return { ok: true, reason: 'active', provider };
    });

    const selectWorkspace = (id, redirect = null) => {
        if (hostLocked.value && activeWorkspaceId.value === id) {
            return;
        }
        router.post(
            route('workspace.switch', id),
            { redirect: redirect || window.location.pathname },
            { preserveScroll: false },
        );
    };

    return {
        tenant,
        workspaces,
        activeWorkspace,
        activeWorkspaceId,
        hostLocked,
        canSend,
        sendingFrom,
        activeSmtp,
        activeProvider,
        activeProviderHealth,
        baseDomain,
        selectWorkspace,
    };
}
