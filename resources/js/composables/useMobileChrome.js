import { ref } from 'vue';

const hideMobileHeader = ref(false);

/**
 * Lets pages (e.g. inbox thread detail) hide the sticky mobile app header
 * so their own chrome can sit flush under the status bar.
 */
export function useMobileChrome() {
    return {
        hideMobileHeader,
        setHideMobileHeader(hidden) {
            hideMobileHeader.value = Boolean(hidden);
        },
    };
}
