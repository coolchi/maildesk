import { computed, reactive } from 'vue';

const state = reactive({
    items: [],
    inboxUnread: 0,
});

export function useNotifications() {
    const unreadCount = computed(
        () => state.items.filter((n) => n.unread).length,
    );

    const inboxUnread = computed(() => state.inboxUnread);

    const markRead = (id) => {
        const item = state.items.find((n) => n.id === id);
        if (item) item.unread = false;
    };

    const markAllRead = () => {
        state.items.forEach((n) => {
            n.unread = false;
        });
    };

    const markThreadRead = (threadId) => {
        state.items.forEach((n) => {
            if (n.threadId === threadId) n.unread = false;
        });
    };

    const setInboxUnread = (count) => {
        state.inboxUnread = Math.max(0, Number(count) || 0);
    };

    return {
        notifications: state.items,
        unreadCount,
        inboxUnread,
        markRead,
        markAllRead,
        markThreadRead,
        setInboxUnread,
    };
}
