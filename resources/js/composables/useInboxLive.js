import { computed, onMounted, onUnmounted, reactive, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useNotifications } from '@/composables/useNotifications';
import { playInboxSound } from '@/composables/useInboxSound';

/**
 * Live inbox: prefer Reverb/Echo WebSocket; fall back to HTTP polling when
 * the socket is missing, disconnected, or fails to subscribe.
 */
const POLL_MS = 5000;

let started = false;
let timer = null;
let inFlight = false;
let pendingPoll = null;
let lastCursor = null;
/** Cursor we already chimed for — avoids double sound from broadcast + poll. */
let lastSoundCursor = null;
let channelName = null;
const inboxListeners = new Set();

const liveState = reactive({
    /** @type {'idle' | 'connecting' | 'connected' | 'polling'} */
    mode: 'idle',
});

export function onInboxActivity(listener) {
    inboxListeners.add(listener);

    onUnmounted(() => {
        inboxListeners.delete(listener);
    });
}

const notifyListeners = (data) => {
    inboxListeners.forEach((fn) => {
        try {
            fn(data);
        } catch {
            // Listener errors must not stop live updates.
        }
    });
};

const chimeForCursor = (cursor) => {
    if (!cursor || cursor === lastSoundCursor) {
        return;
    }
    lastSoundCursor = cursor;
    playInboxSound();
};

const applyPayload = (data, setInboxUnread, { announce = true } = {}) => {
    if (typeof data?.unread === 'number') {
        setInboxUnread(data.unread);
    }

    const cursor = data?.cursor ?? null;
    const changed = Boolean(
        cursor && lastCursor !== null && cursor !== lastCursor,
    );

    if (changed && announce) {
        chimeForCursor(cursor);
        notifyListeners(data);
    }

    if (cursor) {
        lastCursor = cursor;
    }
};

const setMode = (mode) => {
    liveState.mode = mode;
};

export function useInboxLive() {
    const page = usePage();
    const { setInboxUnread } = useNotifications();

    const applyUnread = (count) => {
        if (typeof count === 'number') {
            setInboxUnread(count);
        }
    };

    const organizationId = () => page.props.tenant?.current?.id ?? null;
    const socketConnected = () => liveState.mode === 'connected';

    const stopPolling = () => {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    };

    const startPolling = () => {
        if (timer || socketConnected()) {
            return;
        }
        setMode('polling');
        timer = window.setInterval(() => {
            poll();
        }, POLL_MS);
    };

    const poll = async ({ force = false, announce = true } = {}) => {
        if (!force && socketConnected()) {
            return;
        }
        if (document.visibilityState === 'hidden' && !force) {
            return;
        }
        if (!page.props.auth?.user || !organizationId()) {
            return;
        }

        if (inFlight) {
            pendingPoll = { force: true, announce };
            return;
        }

        inFlight = true;
        try {
            const { data } = await window.axios.get(route('inbox.sync'), {
                headers: { Accept: 'application/json' },
            });
            applyPayload(data, setInboxUnread, { announce });
        } catch {
            // Transient network errors; next tick retries.
        } finally {
            inFlight = false;
            if (pendingPoll) {
                const next = pendingPoll;
                pendingPoll = null;
                poll(next);
            }
        }
    };

    const seedCursor = async () => {
        await poll({ force: true, announce: false });
    };

    const leaveChannel = () => {
        if (window.Echo && channelName) {
            try {
                window.Echo.leave(channelName);
            } catch {
                // ignore
            }
        }
        channelName = null;
    };

    const markConnected = () => {
        setMode('connected');
        stopPolling();
    };

    const markDisconnected = () => {
        if (liveState.mode === 'connected') {
            setMode('polling');
        }
        startPolling();
    };

    /**
     * Broadcast `unread` is mailbox-scoped when mailbox_id is set.
     * Team clients prefer workspace_unread so a single mailbox event does
     * not shrink their org-wide badge.
     */
    const unreadFromPayload = (payload) => {
        const myMailbox = page.props.auth?.mailbox_id ?? null;
        if (myMailbox) {
            return typeof payload?.unread === 'number' ? payload.unread : null;
        }
        if (typeof payload?.workspace_unread === 'number') {
            return payload.workspace_unread;
        }
        if (typeof payload?.unread === 'number') {
            return payload.unread;
        }
        return null;
    };

    const subscribeSocket = () => {
        const orgId = organizationId();
        const echo = window.Echo;

        leaveChannel();

        if (!echo || !orgId || !page.props.broadcasting?.enabled) {
            startPolling();
            seedCursor();
            return;
        }

        channelName = `organizations.${orgId}.inbox`;
        setMode('connecting');

        try {
            const channel = echo.private(channelName);

            channel.listen('.inbox.updated', (payload) => {
                const myMailbox = page.props.auth?.mailbox_id ?? null;
                if (
                    myMailbox &&
                    payload?.mailbox_id &&
                    Number(payload.mailbox_id) !== Number(myMailbox)
                ) {
                    return;
                }

                const unread = unreadFromPayload(payload);
                if (unread !== null) {
                    setInboxUnread(unread);
                }

                // Broadcast itself means new mail — chime even before we have
                // a seeded cursor (first event after connect).
                if (payload?.cursor) {
                    chimeForCursor(payload.cursor);
                    notifyListeners(payload);
                    lastCursor = payload.cursor;
                }

                // Confirm via scoped sync (also covers older payloads without
                // workspace_unread / mailbox-scoped unread).
                poll({ force: true, announce: false });
            });

            // Pusher-protocol connection state (Reverb compatible).
            const pusher = echo.connector?.pusher;
            if (pusher?.connection) {
                pusher.connection.bind('connected', markConnected);
                pusher.connection.bind('disconnected', () => {
                    markDisconnected();
                    poll();
                });
                pusher.connection.bind('unavailable', markDisconnected);
                pusher.connection.bind('failed', markDisconnected);

                if (pusher.connection.state === 'connected') {
                    markConnected();
                    seedCursor();
                } else {
                    // Until connected, keep polling as safety net.
                    startPolling();
                    seedCursor();
                }
            } else {
                markConnected();
                seedCursor();
            }

            channel.error(() => {
                markDisconnected();
            });
        } catch {
            markDisconnected();
        }
    };

    const onVisibility = () => {
        if (document.visibilityState === 'visible' && !socketConnected()) {
            poll();
        }
    };

    onMounted(() => {
        if (started) {
            applyUnread(page.props.inbox_unread);
            subscribeSocket();
            return;
        }
        started = true;
        applyUnread(page.props.inbox_unread);
        subscribeSocket();
        document.addEventListener('visibilitychange', onVisibility);
    });

    watch(
        () => page.props.inbox_unread,
        (count) => applyUnread(count),
    );

    watch(
        () => organizationId(),
        () => {
            lastCursor = null;
            lastSoundCursor = null;
            applyUnread(page.props.inbox_unread);
            subscribeSocket();
            seedCursor();
        },
    );

    const liveConnected = computed(() => liveState.mode === 'connected');
    const liveMode = computed(() => liveState.mode);

    return { poll, liveConnected, liveMode };
}

/**
 * When the inbox page is open, reload thread props after activity.
 */
export function useInboxPageLive() {
    onInboxActivity(() => {
        router.reload({
            only: ['threads'],
            preserveScroll: true,
            preserveState: true,
        });
    });
}
