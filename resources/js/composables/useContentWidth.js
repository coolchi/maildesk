import { computed, reactive, watch } from 'vue';

const STORAGE_KEY = 'maildesk_content_width';

const getPreferred = () => {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'wide' || stored === 'comfortable') return stored;
    } catch {
        /* ignore */
    }

    return 'comfortable';
};

const state = reactive({
    mode: typeof document !== 'undefined' ? getPreferred() : 'comfortable',
});

const apply = (mode) => {
    if (typeof document === 'undefined') return;
    document.documentElement.classList.toggle('content-wide', mode === 'wide');
    try {
        localStorage.setItem(STORAGE_KEY, mode);
    } catch {
        /* ignore */
    }
};

if (typeof document !== 'undefined') {
    apply(state.mode);
}

export function useContentWidth() {
    const isWide = computed(() => state.mode === 'wide');
    const isComfortable = computed(() => state.mode === 'comfortable');

    const setMode = (mode) => {
        if (mode !== 'wide' && mode !== 'comfortable') return;
        state.mode = mode;
        apply(mode);
    };

    const toggle = () => {
        setMode(state.mode === 'wide' ? 'comfortable' : 'wide');
    };

    watch(
        () => state.mode,
        (mode) => apply(mode),
    );

    return {
        state,
        mode: computed(() => state.mode),
        isWide,
        isComfortable,
        setMode,
        toggle,
    };
}
