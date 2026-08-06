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
                searchText: '',
                queueFilter: 'all',
            };
        },

        computed: {
            // Counts describe the events left after the search and queue
            // filters, so the chips always add up to what is on screen.
            scopedEvents() {
                const search = this.searchText.trim().toLowerCase();

                return this.events.filter(event => {
                    if (this.queueFilter !== 'all' && String(event.queue ?? '') !== this.queueFilter) return false;
                    if (!search) return true;

                    return [event.job, event.queue, event.label, event.state]
                        .some(field => String(field ?? '').toLowerCase().includes(search));
                });
            },

            queueOptions() {
                return [...new Set(this.events.map(event => event.queue).filter(Boolean))].sort();
            },

            stateFilters() {
                const counts = this.scopedEvents.reduce((result, event) => {
                    const state = this.jobStateKey(event.state);
                    if (event.state) result[state] = (result[state] ?? 0) + 1;
                    return result;
                }, { pending: 0, reserved: 0, completed: 0, failed: 0 });

                return [
                    { key: 'all', label: 'All', count: this.scopedEvents.length },
                    { key: 'pending', label: 'Pending', count: counts.pending },
                    { key: 'reserved', label: 'Running', count: counts.reserved },
                    { key: 'completed', label: 'Succeeded', count: counts.completed },
                    { key: 'failed', label: 'Failed', count: counts.failed },
                ];
            },

            filteredEvents() {
                if (this.stateFilter === 'all') return this.scopedEvents;
                return this.scopedEvents.filter(event => event.state && this.jobStateKey(event.state) === this.stateFilter);
            },

            isFiltered() {
                return this.stateFilter !== 'all' || this.queueFilter !== 'all' || this.searchText.trim() !== '';
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

            resetFilters() {
                this.stateFilter = 'all';
                this.queueFilter = 'all';
                this.searchText = '';
            },
        },
    };
</script>

<template>
    <div class="lf-pane lf-pane-gap">
        <div class="lf-pane-head">
            <span class="lf-pane-title">Activity</span>
            <span class="lf-pane-meta">
                <template v-if="isFiltered">{{ filteredEvents.length }} of {{ events.length }} recent events</template>
                <template v-else>{{ events.length }} recent events</template>
            </span>
        </div>
        <div class="lf-activity-search">
            <label class="lf-activity-search-field">
                <span class="visually-hidden">Search activity by job, queue, or state</span>
                <svg viewBox="0 0 20 20" aria-hidden="true">
                    <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/>
                </svg>
                <input type="search" v-model="searchText" placeholder="Search job, queue, or state…">
            </label>
            <label class="lf-activity-queue-filter">
                <span class="visually-hidden">Filter activity by queue</span>
                <select v-model="queueFilter">
                    <option value="all">All queues</option>
                    <option v-for="queue in queueOptions" :key="queue" :value="queue">{{ queue }}</option>
                </select>
            </label>
            <button class="lf-activity-reset" type="button" v-if="isFiltered" @click="resetFilters">Clear</button>
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
                <span class="lf-event-time" :title="absoluteTimeWithAge(event.timestamp)">
                    <span class="lf-event-clock">{{ clockTime(event.timestamp) }}</span>
                    <span class="lf-event-date">{{ clockDate(event.timestamp) }}</span>
                </span>
                <template v-if="isStructured(event)">
                    <span class="lf-event-job">{{ shortJobName(event.job) }}</span>
                    <span class="lf-event-queue">{{ event.queue }}</span>
                    <span class="lf-jstate" :class="'lf-jstate-' + jobStateKey(event.state)">{{ stateLabel(event.state) }}</span>
                </template>
                <span class="lf-event-job" v-else>{{ event.label }}</span>
            </component>
            <div class="lf-empty" v-if="filteredEvents.length === 0 && isFiltered">No events match these filters.</div>
            <div class="lf-empty" v-else-if="filteredEvents.length === 0">No recent events.</div>
        </div>
    </div>
</template>
