import { computed, ref } from 'vue';
import {
    adminAccounts,
    adminProviders,
    adminStats,
    adminSubdomains,
    adminSubscriptions,
    providerHealthForAccount,
    recountProviderTenants,
    smtpCredentialsForProvider,
    workspaceColorByAccountId,
} from '@/data/adminMock';

const cloneProviders = (list) =>
    list.map((p) => ({
        ...p,
        regions: [...(p.regions || [])],
        features: [...(p.features || [])],
        config: (p.config || []).map((c) => ({ ...c })),
    }));

const cloneAccounts = (list) => list.map((a) => ({ ...a }));

const providers = ref(cloneProviders(adminProviders));
const accounts = ref(cloneAccounts(adminAccounts));
const subdomains = ref(adminSubdomains.map((d) => ({ ...d })));
const subscriptions = ref(adminSubscriptions.map((s) => ({ ...s })));
const stats = ref({ ...adminStats });
const activeWorkspaceId = ref(1);

recountProviderTenants(providers.value, accounts.value);

const findProvider = (id) => providers.value.find((p) => p.id === id) || null;

const findAccount = (id) =>
    accounts.value.find((a) => String(a.id) === String(id)) || null;

const activeProviders = computed(() =>
    providers.value.filter((p) => p.status === 'active'),
);

const defaultProvider = computed(
    () =>
        providers.value.find((p) => p.default && p.status === 'active') ||
        activeProviders.value[0] ||
        null,
);

const workspaces = computed(() =>
    accounts.value.map((a) => {
        const health = providerHealthForAccount(a, providers.value);
        return {
            id: a.id,
            name: a.name,
            plan: a.plan,
            product: a.product,
            provider: a.provider,
            providerName: health.provider?.name || a.provider || 'None',
            providerDriver: health.provider?.driver || null,
            providerOk: health.ok,
            providerReason: health.reason,
            email: a.email,
            owner: a.owner,
            subdomain: a.subdomain,
            host: a.subdomain ? `${a.subdomain}.maildesk.test` : null,
            customDomain: a.customDomain,
            status: a.status,
            color: workspaceColorByAccountId[a.id] || 'cyan',
        };
    }),
);

const activeWorkspace = computed(
    () =>
        workspaces.value.find((w) => w.id === activeWorkspaceId.value) ||
        workspaces.value[0] ||
        null,
);

const activeAccount = computed(() =>
    activeWorkspace.value ? findAccount(activeWorkspace.value.id) : null,
);

const activeProvider = computed(() => {
    const account = activeAccount.value;
    if (!account) return null;
    return findProvider(account.provider);
});

const activeProviderHealth = computed(() =>
    providerHealthForAccount(activeAccount.value, providers.value),
);

const activeSmtp = computed(() =>
    smtpCredentialsForProvider(activeProviderHealth.value.provider),
);

const canSend = computed(() => activeProviderHealth.value.ok);

const syncStats = () => {
    stats.value.providers = providers.value.filter(
        (p) => p.status === 'active',
    ).length;
    stats.value.accounts = accounts.value.length;
};

/**
 * Replace client state with server-seeded admin payload.
 * @param {{
 *   accounts?: Array<Record<string, unknown>>,
 *   providers?: Array<Record<string, unknown>>,
 *   subscriptions?: Array<Record<string, unknown>>,
 *   hosts?: Array<Record<string, unknown>>,
 *   stats?: Record<string, unknown>,
 * }} payload
 */
const hydrate = (payload = {}) => {
    if (Array.isArray(payload.providers)) {
        providers.value = cloneProviders(payload.providers);
    }
    if (Array.isArray(payload.accounts)) {
        accounts.value = cloneAccounts(payload.accounts);
    }
    if (Array.isArray(payload.subscriptions)) {
        subscriptions.value = payload.subscriptions.map((s) => ({ ...s }));
    }
    if (Array.isArray(payload.hosts)) {
        subdomains.value = payload.hosts.map((d) => ({ ...d }));
    }
    if (payload.stats && typeof payload.stats === 'object') {
        stats.value = { ...stats.value, ...payload.stats };
    }
    recountProviderTenants(providers.value, accounts.value);
    syncStats();
};

const assignProvider = (accountId, providerId) => {
    const account = findAccount(accountId);
    if (!account) return false;
    account.provider = providerId;
    recountProviderTenants(providers.value, accounts.value);
    return true;
};

const upsertProvider = (provider) => {
    const idx = providers.value.findIndex((p) => p.id === provider.id);
    if (idx === -1) {
        providers.value.push(provider);
    } else {
        providers.value[idx] = provider;
    }
    recountProviderTenants(providers.value, accounts.value);
    syncStats();
};

const deleteProvider = (providerId) => {
    const target = findProvider(providerId);
    if (!target) return { ok: false, reason: 'missing' };
    if (target.default) return { ok: false, reason: 'default' };

    providers.value = providers.value.filter((p) => p.id !== providerId);
    // Leave account.provider as-is so UI can show orphaned state.
    recountProviderTenants(providers.value, accounts.value);
    syncStats();
    return { ok: true };
};

const setDefaultProvider = (providerId) => {
    const target = findProvider(providerId);
    if (!target || target.status !== 'active') return false;
    providers.value.forEach((p) => {
        p.default = p.id === providerId;
    });
    return true;
};

const selectWorkspace = (id) => {
    if (!findAccount(id)) return false;
    activeWorkspaceId.value = id;
    return true;
};

const createWorkspace = ({ name, email, color = 'cyan' }) => {
    const id = Date.now();
    const subdomain = name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 24) || `ws-${id}`;

    const providerId = defaultProvider.value?.id || null;
    const account = {
        id,
        name,
        owner: 'You',
        email: email || 'you@maildesk.test',
        plan: 'Free',
        product: 'transactional',
        provider: providerId,
        status: 'trial',
        subdomain,
        customDomain: null,
        mrr: 0,
        seats: 1,
        emails30d: 0,
        created: 'just now',
        region: 'us-east-1',
    };

    accounts.value.push(account);
    workspaceColorByAccountId[id] = color;
    subdomains.value.push({
        id: Date.now() + 1,
        accountId: id,
        account: name,
        subdomain,
        host: `${subdomain}.maildesk.test`,
        status: 'provisioning',
        ssl: false,
        created: 'just now',
    });
    recountProviderTenants(providers.value, accounts.value);
    syncStats();
    activeWorkspaceId.value = id;
    return account;
};

export function usePlatform() {
    return {
        providers,
        accounts,
        subdomains,
        subscriptions,
        stats,
        activeWorkspaceId,
        activeProviders,
        defaultProvider,
        workspaces,
        activeWorkspace,
        activeAccount,
        activeProvider,
        activeProviderHealth,
        activeSmtp,
        canSend,
        findProvider,
        findAccount,
        assignProvider,
        upsertProvider,
        deleteProvider,
        setDefaultProvider,
        selectWorkspace,
        createWorkspace,
        hydrate,
        recount: () =>
            recountProviderTenants(providers.value, accounts.value),
    };
}
