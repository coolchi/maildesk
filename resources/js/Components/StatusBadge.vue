<script setup>
const props = defineProps({
    status: { type: String, required: true },
});

const styles = {
    delivered: 'bg-emerald-500/15 text-emerald-400 ring-emerald-500/20',
    sent: 'bg-cyan-400/15 text-cyan-300 ring-cyan-400/20',
    bounced: 'bg-rose-500/15 text-rose-400 ring-rose-500/20',
    suppressed: 'bg-zinc-500/20 text-zinc-300 ring-zinc-500/20',
    received: 'bg-sky-500/15 text-sky-300 ring-sky-500/20',
    pending: 'bg-amber-500/15 text-amber-300 ring-amber-500/20',
    verified: 'bg-emerald-500/15 text-emerald-400 ring-emerald-500/20',
    failing: 'bg-rose-500/15 text-rose-400 ring-rose-500/20',
    published: 'bg-cyan-400/15 text-cyan-300 ring-cyan-400/20',
    draft: 'bg-zinc-500/20 text-zinc-300 ring-zinc-500/20',
    scheduled: 'bg-violet-500/15 text-violet-300 ring-violet-500/20',
    subscribed: 'bg-emerald-500/15 text-emerald-400 ring-emerald-500/20',
    unsubscribed: 'bg-zinc-500/20 text-zinc-300 ring-zinc-500/20',
    queued: 'bg-amber-500/15 text-amber-300 ring-amber-500/20',
    enabled: 'bg-emerald-500/15 text-emerald-400 ring-emerald-500/20',
    disabled: 'bg-zinc-500/20 text-zinc-300 ring-zinc-500/20',
    paused: 'bg-amber-500/15 text-amber-300 ring-amber-500/20',
    active: 'bg-emerald-500/15 text-emerald-400 ring-emerald-500/20',
    inactive: 'bg-zinc-500/20 text-zinc-400 ring-zinc-500/20',
    trial: 'bg-sky-500/15 text-sky-300 ring-sky-500/20',
    past_due: 'bg-amber-500/15 text-amber-300 ring-amber-500/20',
    suspended: 'bg-rose-500/15 text-rose-400 ring-rose-500/20',
    pending_dns: 'bg-amber-500/15 text-amber-300 ring-amber-500/20',
    provisioning: 'bg-violet-500/15 text-violet-300 ring-violet-500/20',
    staff: 'bg-sky-500/15 text-sky-300 ring-sky-500/20',
    developer: 'bg-violet-500/15 text-violet-300 ring-violet-500/20',
    business_owner: 'bg-amber-500/15 text-amber-300 ring-amber-500/20',
    admin: 'bg-amber-500/15 text-amber-300 ring-amber-500/20',
};

const labels = {
    enabled: 'Enabled',
    disabled: 'Disabled',
    business_owner: 'Admin',
    admin: 'Admin',
    staff: 'Staff',
    developer: 'Developer',
    active: 'Active',
    inactive: 'Inactive',
    trial: 'Trial',
    past_due: 'Past due',
    suspended: 'Suspended',
    pending_dns: 'Pending DNS',
    provisioning: 'Provisioning',
};
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
        :class="[
            styles[status] || styles.suppressed,
            labels[status] ? '' : 'capitalize',
        ]"
    >
        {{ labels[status] || status }}
    </span>
</template>
