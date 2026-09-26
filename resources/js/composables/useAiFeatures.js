import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useAiFeatures() {
    const page = usePage();
    const features = computed(() => page.props.ai?.features || {});
    const aiEnabled = computed(() => Boolean(page.props.ai?.enabled));

    const enabled = (key) => Boolean(features.value?.[key]);

    return {
        aiEnabled,
        features,
        enabled,
        composeAssist: computed(() => enabled('compose_assist')),
        threadSummary: computed(() => enabled('thread_summary')),
        broadcastAssist: computed(() => enabled('broadcast_assist')),
        automationSmartSteps: computed(() => enabled('automation_smart_steps')),
        nlSegments: computed(() => enabled('nl_segments')),
        bounceExplanations: computed(() => enabled('bounce_explanations')),
        inAppHelp: computed(() => enabled('in_app_help')),
        abuseDetection: computed(() => enabled('abuse_detection')),
        replyDraft: computed(() => enabled('reply_draft')),
        smartTriage: computed(() => enabled('smart_triage')),
    };
}
