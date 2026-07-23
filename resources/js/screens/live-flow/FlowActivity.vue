<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            events: { type: Array, default: () => [] },
        },

        data() {
            return {
                stateFilter: 'all',
            };
        },

        computed: {
            stateFilters() {
                const counts = this.events.reduce((result, event) => {
                    const state = this.jobStateKey(event.state);
                    if (event.state) result[state] = (result[state] ?? 0) + 1;
                    return result;
                }, { pending: 0, reserved: 0, completed: 0, failed: 0 });

                return [
                    { key: 'all', label: 'All', count: this.events.length },
                    { key: 'pending', label: 'Pending', count: counts.pending },
                    { key: 'reserved', label: 'Running', count: counts.reserved },
                    { key: 'completed', label: 'Succeeded', count: counts.completed },
                    { key: 'failed', label: 'Failed', count: counts.failed },
                ];
            },

            filteredEvents() {
                if (this.stateFilter === 'all') return this.events;
                return this.events.filter(event => event.state && this.jobStateKey(event.state) === this.stateFilter);
            },
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

            // Supervisor fallback events carry only a prose label, so those
            // rows span the job column instead of splitting into fields.
            isStructured(event) {
                return Boolean(event.job && event.state);
            },

            stateLabel(state) {
                return { reserved: 'Running', completed: 'Succeeded' }[this.jobStateKey(state)] ?? state;
            },
        },
    };
</script>

<template>
    <div class="lf-pane lf-pane-gap">
        <div class="lf-pane-head">
            <span class="lf-pane-title">Activity</span>
            <span class="lf-pane-meta">{{ events.length }} recent events</span>
        </div>
        <div class="lf-activity-filters" role="group" aria-label="Filter activity by state">
            <button
                v-for="filter in stateFilters"
                :key="filter.key"
                class="lf-activity-filter"
                :class="[
                    'lf-activity-filter-' + filter.key,
                    { 'lf-activity-filter-active': stateFilter === filter.key },
                ]"
                type="button"
                :aria-pressed="stateFilter === filter.key"
                @click="stateFilter = filter.key"
            >
                <span>{{ filter.label }}</span>
                <span class="lf-activity-filter-count">{{ formatCount(filter.count) }}</span>
            </button>
        </div>
        <div class="lf-activity-columns" aria-hidden="true">
            <span>When</span>
            <span>Job</span>
            <span>Queue</span>
            <span>State</span>
        </div>
        <div class="lf-activity">
            <component
                v-for="event in filteredEvents"
                :key="event.id ?? event.timestamp + '-' + event.label"
                :is="failedJobRoute(event) ? 'router-link' : 'div'"
                :to="failedJobRoute(event) ?? undefined"
                class="lf-event"
                :class="{ 'lf-event-link': failedJobRoute(event), 'lf-event-plain': !isStructured(event) }"
            >
                <span class="lf-event-time">{{ relativeTime(event) }}</span>
                <template v-if="isStructured(event)">
                    <span class="lf-event-job">{{ shortJobName(event.job) }}</span>
                    <span class="lf-event-queue">{{ event.queue }}</span>
                    <span class="lf-jstate" :class="'lf-jstate-' + jobStateKey(event.state)">{{ stateLabel(event.state) }}</span>
                </template>
                <span class="lf-event-job" v-else>{{ event.label }}</span>
            </component>
            <div class="lf-empty" v-if="filteredEvents.length === 0">No {{ stateFilter === 'all' ? 'recent' : stateFilters.find(filter => filter.key === stateFilter)?.label.toLowerCase() }} events.</div>
        </div>
    </div>
</template>
