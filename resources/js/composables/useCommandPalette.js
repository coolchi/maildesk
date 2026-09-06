import { reactive } from 'vue';

const state = reactive({
    open: false,
});

export function useCommandPalette() {
    return {
        state,
        open: () => {
            state.open = true;
        },
        close: () => {
            state.open = false;
        },
        toggle: () => {
            state.open = !state.open;
        },
    };
}
