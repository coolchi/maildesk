import { reactive } from 'vue';

const state = reactive({
    open: false,
    mode: 'marketing',
});

export function usePlansModal() {
    return {
        state,
        open: (mode = 'marketing') => {
            state.mode = mode;
            state.open = true;
        },
        close: () => {
            state.open = false;
        },
    };
}
