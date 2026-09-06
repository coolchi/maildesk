import { reactive } from 'vue';

const state = reactive({
    items: [],
});

let seed = 0;

export function useToast() {
    const push = (message, type = 'success', duration = 3200) => {
        const id = ++seed;
        state.items.push({ id, message, type });
        window.setTimeout(() => dismiss(id), duration);
        return id;
    };

    const dismiss = (id) => {
        const i = state.items.findIndex((t) => t.id === id);
        if (i !== -1) state.items.splice(i, 1);
    };

    return {
        toasts: state.items,
        success: (message, duration) => push(message, 'success', duration),
        error: (message, duration) => push(message, 'error', duration),
        info: (message, duration) => push(message, 'info', duration),
        dismiss,
    };
}
