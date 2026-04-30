<script type="text/ecmascript-6">
    export default {
        props: {
            events: { type: Array, default: () => [] },
        },

        methods: {
            formatDuration(value) {
                if (value === null || value === undefined) return '—';
                const s = Number(value);
                if (s < 60) return `${s}s`;
                if (s < 3600) return `${Math.round(s / 60)}m`;
                if (s < 86400) return `${(s / 3600).toFixed(1)}h`;
                return `${(s / 86400).toFixed(1)}d`;
            },

            relativeTime(event) {
                const ts = Number(event?.timestamp ?? 0);
                if (!ts) return '—';

                const delta = Math.max(0, Math.floor(Date.now() / 1000) - ts);
                if (delta < 5) return 'now';
                return this.formatDuration(delta);
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
