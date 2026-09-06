import { reactive } from 'vue';

const state = reactive({
    open: false,
    defaults: null,
});

export function useComposeModal() {
    return {
        state,
        open: (defaults = null) => {
            state.defaults = defaults;
            state.open = true;
        },
        close: () => {
            state.open = false;
            state.defaults = null;
        },
    };
}
