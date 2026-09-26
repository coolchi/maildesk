import { reactive, ref } from 'vue';

/**
 * Global state for the floating compose panel.
 *
 * - open: a draft exists (panel is docked bottom-right)
 * - minimized: collapsed to its title bar, the draft is kept
 * - expanded: enlarged to a centered dialog for long emails
 */
const state = reactive({
    open: false,
    minimized: false,
    expanded: false,
    defaults: null,
    // Bumped on every open() so a fresh draft starts even if one was showing.
    session: 0,
});

/**
 * The draft lives here (not inside the component) so it survives page
 * navigation: the panel stays open while you browse, like Gmail.
 */
export const composeDraft = {
    form: ref(null),
    attachments: ref([]),
    pristine: ref(''),
    draftId: ref(null),
};

export function useComposeModal() {
    return {
        state,
        open: (defaults = null) => {
            state.defaults = defaults;
            composeDraft.draftId.value = defaults?.draft_id ?? null;
            state.minimized = false;
            state.expanded = false;
            state.open = true;
            state.session += 1;
        },
        close: () => {
            state.open = false;
            state.minimized = false;
            state.expanded = false;
            state.defaults = null;
            composeDraft.draftId.value = null;
        },
        toggleMinimized: () => {
            state.minimized = !state.minimized;
            if (state.minimized) state.expanded = false;
        },
        toggleExpanded: () => {
            state.expanded = !state.expanded;
            if (state.expanded) state.minimized = false;
        },
    };
}
