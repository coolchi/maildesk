import { computed, onMounted, onUnmounted, reactive, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useNotifications } from '@/composables/useNotifications';

/**
 * Live inbox: prefer Reverb/Echo WebSocket; fall back to HTTP polling when
 * the socket is missing, disconnected, or fails to subscribe.
 */
const POLL_MS = 5000;

let started = false;
let timer = null;
let inFlight = false;
let lastCursor = null;
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

const applyPayload = (data, setInboxUnread) => {
    if (typeof data?.unread === 'number') {
        setInboxUnread(data.unread);
    }

    if (data?.cursor && lastCursor !== null && data.cursor !== lastCursor) {
        notifyListeners(data);
    }

    if (data?.cursor) {
        lastCursor = data.cursor;
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
        timer = window.setInterval(poll, POLL_MS);
    };

    const poll = async () => {
        if (socketConnected() || inFlight || document.visibilityState === 'hidden') {
            return;
        }
        if (!page.props.auth?.user || !organizationId()) {
            return;
        }

        inFlight = true;
        try {
            const { data } = await window.axios.get(route('inbox.sync'), {
                headers: { Accept: 'application/json' },
            });
            applyPayload(data, setInboxUnread);
        } catch {
            // Transient network errors; next tick retries.
        } finally {
            inFlight = false;
        }
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

    const subscribeSocket = () => {
        const orgId = organizationId();
        const echo = window.Echo;

        leaveChannel();

        if (!echo || !orgId || !page.props.broadcasting?.enabled) {
            startPolling();
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

                // Scoped mailboxes must refresh unread via sync (broadcast
                // unread is workspace-wide for the team badge).
                if (myMailbox) {
                    if (payload?.cursor) {
                        if (lastCursor !== null && payload.cursor !== lastCursor) {
                            notifyListeners(payload);
                        }
                        lastCursor = payload.cursor;
                    }
                    poll();
                    return;
                }

                applyPayload(payload, setInboxUnread);
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
                } else {
                    // Until connected, keep polling as safety net.
                    startPolling();
                }
            } else {
                markConnected();
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
            applyUnread(page.props.inbox_unread);
            subscribeSocket();
            if (!socketConnected()) {
                poll();
            }
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
