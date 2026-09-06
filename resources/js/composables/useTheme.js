import { computed, reactive, watch } from 'vue';

const STORAGE_KEY = 'maildesk_theme';

const getPreferred = () => {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'light' || stored === 'dark') return stored;
    } catch {
        /* ignore */
    }
    if (typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: light)').matches) {
        return 'light';
    }
    return 'dark';
};

const state = reactive({
    theme: typeof document !== 'undefined' ? getPreferred() : 'dark',
});

const apply = (theme) => {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;
    root.classList.toggle('dark', theme === 'dark');
    root.classList.toggle('light', theme === 'light');
    root.style.colorScheme = theme;
    try {
        localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        /* ignore */
    }
};

// Apply immediately when module loads (client)
if (typeof document !== 'undefined') {
    apply(state.theme);
}

export function useTheme() {
    const isDark = computed(() => state.theme === 'dark');
    const isLight = computed(() => state.theme === 'light');

    const setTheme = (theme) => {
        if (theme !== 'light' && theme !== 'dark') return;
        state.theme = theme;
        apply(theme);
    };

    const toggle = () => {
        setTheme(state.theme === 'dark' ? 'light' : 'dark');
    };

    watch(
        () => state.theme,
        (theme) => apply(theme),
    );

    return {
        state,
        theme: computed(() => state.theme),
        isDark,
        isLight,
        setTheme,
        toggle,
    };
}
