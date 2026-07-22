<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            events: { type: Array, default: () => [] },
        },

        methods: {
            // Failed events carry the Horizon job id (a UUID) when the job
            // is still on record; the sha1 fallback id can't be inspected.
            failedJobRoute(event) {
                if (event.state !== 'failed') return null;
                if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(String(event.id ?? ''))) {
                    return { name: 'failed-jobs-preview', params: { jobId: event.id } };
                }
                return { name: 'failed-jobs' };
            },
        },
    };
</script>

<template>
    <div class="lf-pane lf-pane-gap">
        <div class="lf-pane-head">
            <span class="lf-pane-title">Activity</span>
            <span class="lf-tag">recent</span>
        </div>
        <div class="lf-activity">
            <component
                v-for="event in events"
                :key="event.id ?? event.timestamp + '-' + event.label"
                :is="failedJobRoute(event) ? 'router-link' : 'div'"
                :to="failedJobRoute(event) ?? undefined"
                class="lf-event"
                :class="{ 'lf-event-link': failedJobRoute(event) }"
            >
                <span class="lf-event-time">{{ relativeTime(event) }}</span>
                <span class="lf-event-label" :class="'lf-st-' + event.status">{{ event.label }}</span>
            </component>
            <div class="lf-empty" v-if="events.length === 0">No recent flow events.</div>
        </div>
    </div>
</template>
