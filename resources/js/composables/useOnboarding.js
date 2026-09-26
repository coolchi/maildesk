import { computed, reactive } from 'vue';
import { usePage } from '@inertiajs/vue3';

const STORAGE_KEY = 'maildesk_onboarding';

export const ONBOARDING_STEPS = ['domain', 'apiKey', 'send', 'webhook'];

const defaults = Object.fromEntries(ONBOARDING_STEPS.map((key) => [key, false]));

const load = () => {
    try {
        return { ...defaults, ...JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') };
    } catch {
        return { ...defaults };
    }
};

const state = reactive({
    open: false,
    // Local fallback only (used when the server can't tell us, e.g. outside a workspace).
    steps: load(),
});

const persist = () => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state.steps));
    } catch {
        /* ignore */
    }
};

/**
 * Merge server-derived completion (shared `onboarding` prop) with the local
 * fallback. A step is done when the workspace has really done it.
 */
export function resolveSteps(server, local = {}) {
    return Object.fromEntries(
        ONBOARDING_STEPS.map((key) => [
            key,
            server && typeof server[key] === 'boolean' ? server[key] : Boolean(local[key]),
        ]),
    );
}

/** The first step that isn't done yet, or null when everything is complete. */
export function nextStep(steps) {
    return ONBOARDING_STEPS.find((key) => !steps[key]) ?? null;
}

export function useOnboarding() {
    let page = null;
    try {
        page = usePage();
    } catch {
        page = null;
    }

    const steps = computed(() => resolveSteps(page?.props?.onboarding, state.steps));
    const completedCount = () => Object.values(steps.value).filter(Boolean).length;
    const next = computed(() => nextStep(steps.value));

    return {
        state,
        steps,
        next,
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
        total: ONBOARDING_STEPS.length,
    };
}
