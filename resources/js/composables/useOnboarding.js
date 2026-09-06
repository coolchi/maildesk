import { reactive } from 'vue';

const STORAGE_KEY = 'maildesk_onboarding';

const defaults = {
    domain: false,
    apiKey: false,
    send: false,
    webhook: false,
};

const load = () => {
    try {
        return { ...defaults, ...JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') };
    } catch {
        return { ...defaults };
    }
};

const state = reactive({
    open: false,
    steps: load(),
});

const persist = () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state.steps));
};

export function useOnboarding() {
    const completedCount = () =>
        Object.values(state.steps).filter(Boolean).length;

    return {
        state,
        open: () => {
            state.open = true;
        },
        close: () => {
            state.open = false;
        },
        complete: (key) => {
            state.steps[key] = true;
            persist();
        },
        reset: () => {
            state.steps = { ...defaults };
            persist();
        },
        completedCount,
        total: Object.keys(defaults).length,
    };
}
