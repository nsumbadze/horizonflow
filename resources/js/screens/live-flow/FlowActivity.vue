<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            events: { type: Array, default: () => [] },
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
            <div
                v-for="(event, i) in events"
                :key="i"
                class="lf-event"
            >
                <span class="lf-event-time">{{ relativeTime(event) }}</span>
                <span class="lf-dot" :class="'lf-dot-' + event.status" style="flex-shrink:0"></span>
                <span class="lf-event-label">{{ event.label }}</span>
            </div>
            <div class="lf-empty" v-if="events.length === 0">No recent flow events.</div>
        </div>
    </div>
</template>
