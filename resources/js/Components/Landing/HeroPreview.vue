<script setup>
import {
    Globe,
    Inbox,
    Mail,
    Megaphone,
    Reply,
    ShieldCheck,
    Users,
    Webhook,
} from '@lucide/vue';

// Static, illustrative mock of the real workspace UI (sidebar sections and
// statuses mirror the app). No live data is shown on the public page.
const nav = [
    { label: 'Emails', icon: Mail },
    { label: 'Inbox', icon: Inbox, active: true, count: 2 },
    { label: 'Broadcasts', icon: Megaphone },
    { label: 'Audience', icon: Users },
    { label: 'Domains', icon: Globe },
    { label: 'Webhooks', icon: Webhook },
];

const threads = [
    {
        from: 'Maya Chen',
        subject: 'Re: Invoice #1042 attachment',
        preview: 'Thanks! Could you resend the PDF to our finance team?',
        time: '2m',
        unread: true,
        active: true,
    },
    {
        from: 'Northwind Ops',
        subject: 'Delivery question',
        preview: 'Our welcome emails are landing perfectly now.',
        time: '18m',
        unread: true,
    },
    {
        from: 'support@',
        subject: 'Password reset',
        preview: 'Sent via API · delivered',
        time: '1h',
        delivered: true,
    },
];
</script>

<template>
    <div class="relative" aria-hidden="true">
        <div
            class="lp-preview lp-float relative overflow-hidden rounded-2xl"
        >
            <div
                class="lp-preview-side flex items-center gap-2 border-b px-4 py-3"
            >
                <span class="h-2.5 w-2.5 rounded-full bg-rose-400/80" />
                <span class="h-2.5 w-2.5 rounded-full bg-amber-400/80" />
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/80" />
                <span class="lp-muted ml-3 text-xs">Acme · MailDesk</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-[150px_1fr]">
                <nav
                    class="lp-preview-side hidden space-y-0.5 border-r p-2.5 text-[13px] sm:block"
                >
                    <div
                        v-for="item in nav"
                        :key="item.label"
                        class="lp-preview-nav lp-text-2 flex items-center gap-2 rounded-md px-2 py-1.5"
                        :class="{ 'is-active': item.active }"
                    >
                        <component :is="item.icon" :size="14" />
                        <span class="flex-1">{{ item.label }}</span>
                        <span
                            v-if="item.count"
                            class="lp-badge-accent rounded-full px-1.5 text-[10px] font-semibold"
                            >{{ item.count }}</span
                        >
                    </div>
                </nav>
                <div>
                    <div
                        class="lp-preview-row flex items-center justify-between border-b px-4 py-2.5"
                    >
                        <span class="lp-text text-sm font-semibold">Inbox</span>
                        <span class="lp-muted text-xs">support@acme.com</span>
                    </div>
                    <div
                        v-for="t in threads"
                        :key="t.subject"
                        class="lp-preview-row border-b px-4 py-3 last:border-b-0"
                        :class="{ 'is-active': t.active }"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="h-1.5 w-1.5 shrink-0 rounded-full"
                                :class="t.unread ? 'lp-dot-unread' : 'opacity-0'"
                            />
                            <span
                                class="lp-text truncate text-sm"
                                :class="t.unread ? 'font-semibold' : 'font-medium'"
                                >{{ t.from }}</span
                            >
                            <span
                                v-if="t.delivered"
                                class="lp-badge-ok rounded-full px-1.5 py-px text-[10px] font-medium"
                                >Delivered</span
                            >
                            <span class="lp-muted ml-auto shrink-0 text-xs">{{
                                t.time
                            }}</span>
                        </div>
                        <div class="lp-text-2 mt-0.5 truncate pl-3.5 text-[13px]">
                            {{ t.subject }}
                        </div>
                        <div class="lp-muted mt-0.5 truncate pl-3.5 text-xs">
                            {{ t.preview }}
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2 px-4 py-3">
                        <span
                            class="lp-btn lp-btn-primary px-3 py-1.5 text-xs"
                        >
                            <Reply :size="13" />
                            Reply
                        </span>
                        <span
                            class="lp-btn lp-btn-secondary px-3 py-1.5 text-xs"
                            >Forward</span
                        >
                    </div>
                </div>
            </div>
        </div>

        <!-- Floating domain-auth card -->
        <div
            class="lp-card lp-float absolute -bottom-8 -left-4 hidden w-60 rounded-xl p-3.5 sm:block lg:-left-10"
        >
            <div class="flex items-center gap-2">
                <span
                    class="lp-icon-tile flex h-7 w-7 items-center justify-center rounded-lg"
                >
                    <ShieldCheck :size="14" />
                </span>
                <div class="min-w-0">
                    <div class="lp-text truncate text-[13px] font-semibold">
                        acme.com verified
                    </div>
                    <div class="lp-muted text-[11px]">DNS checks passed</div>
                </div>
            </div>
            <div class="mt-3 flex gap-1.5">
                <span
                    v-for="r in ['SPF', 'DKIM', 'DMARC']"
                    :key="r"
                    class="lp-badge-ok rounded-md px-1.5 py-0.5 text-[10px] font-semibold"
                    >{{ r }} ✓</span
                >
            </div>
        </div>

        <!-- Floating API card -->
        <div
            class="lp-code lp-float absolute -right-3 -top-6 hidden rounded-xl px-3.5 py-2.5 font-mono text-[11px] leading-5 md:block lg:-right-8"
        >
            <div><span class="c">POST</span> /api/v1/emails</div>
            <div>
                <span class="k">status</span>: <span class="s">"queued"</span>
            </div>
        </div>
    </div>
</template>
