<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            comparison: { type: Array, default: () => [] },
            incidents: { type: Array, default: () => [] },
            monitoredTags: { type: Array, default: () => [] },
            timeRange: { type: String, default: 'Last 15m' },
        },

        emits: ['navigate', 'notice'],

        data() {
            return {
                batches: [],
                batchesReady: false,
                batchError: false,
                retryingBatches: [],
            };
        },

        mounted() {
            this.loadBatches();
        },

        methods: {
            loadBatches() {
                this.batchesReady = false;
                this.batchError = false;

                return this.$http.get(Horizon.basePath + '/api/batches')
                    .then(response => { this.batches = (response.data.batches ?? []).slice(0, 8); })
                    .catch(() => {
                        this.batches = [];
                        this.batchError = true;
                    })
                    .finally(() => { this.batchesReady = true; });
            },

            retryBatch(batch) {
                if (!batch?.id || this.retryingBatches.includes(batch.id)) return;
                this.retryingBatches = [...this.retryingBatches, batch.id];

                return this.$http.post(`${Horizon.basePath}/api/batches/retry/${batch.id}`)
                    .then(() => {
                        this.$emit('notice', { type: 'success', message: `${batch.name || 'Batch'} retry was dispatched.` });
                        return this.loadBatches();
                    })
                    .catch(() => this.$emit('notice', { type: 'error', message: `${batch.name || 'Batch'} could not be retried.` }))
                    .finally(() => { this.retryingBatches = this.retryingBatches.filter(id => id !== batch.id); });
            },

            batchStatus(batch) {
                if (batch.cancelledAt) return 'Cancelled';
                if (Number(batch.pendingJobs ?? 0) === 0) return Number(batch.failedJobs ?? 0) > 0 ? 'Finished with failures' : 'Finished';
                if (Number(batch.failedJobs ?? 0) > 0) return 'Failures';
                return 'Running';
            },

            batchTone(batch) {
                if (batch.cancelledAt) return 'warning';
                if (Number(batch.failedJobs ?? 0) > 0) return 'critical';
                if (Number(batch.pendingJobs ?? 0) > 0) return 'info';
                return 'healthy';
            },

            incidentComponent(incident) {
                if (incident.to) return 'router-link';
                if (incident.tab) return 'button';
                return 'div';
            },
        },
    };
</script>

<template>
    <div class="lf-insights">
        <section class="lf-pane lf-insight-card lf-insight-comparison">
            <div class="lf-pane-head">
                <span class="lf-pane-title">Performance comparison</span>
                <span class="lf-pane-meta">{{ timeRange }}</span>
            </div>
            <div class="lf-compare-list">
                <div class="lf-compare-row" v-for="metric in comparison" :key="metric.key">
                    <div class="lf-compare-name">{{ metric.label }}</div>
                    <div class="lf-compare-value">
                        <span>{{ metric.current }}</span>
                        <small>current</small>
                    </div>
                    <div class="lf-compare-value lf-compare-previous">
                        <span>{{ metric.previous }}</span>
                        <small>{{ metric.referenceLabel }}</small>
                    </div>
                    <span class="lf-compare-delta" :class="'lf-compare-delta-' + metric.tone">{{ metric.delta }}</span>
                </div>
            </div>
            <router-link class="lf-insight-footer-link" :to="{ name: 'metrics-queues' }">Open queue metrics →</router-link>
        </section>

        <section class="lf-pane lf-insight-card lf-insight-incidents">
            <div class="lf-pane-head">
                <span class="lf-pane-title">Operational timeline</span>
                <span class="lf-pane-meta">{{ incidents.length }} signals</span>
            </div>
            <div class="lf-incident-list" v-if="incidents.length">
                <component
                    v-for="incident in incidents"
                    :key="incident.id"
                    :is="incidentComponent(incident)"
                    :to="incident.to ?? undefined"
                    :type="incident.tab ? 'button' : undefined"
                    class="lf-incident"
                    @click="incident.tab ? $emit('navigate', { tab: incident.tab, mode: incident.mode }) : null"
                >
                    <span class="lf-incident-mark" :class="'lf-incident-mark-' + incident.severity" aria-hidden="true"></span>
                    <span class="lf-incident-main">
                        <strong>{{ incident.title }}</strong>
                        <small>{{ incident.detail }}</small>
                    </span>
                    <span class="lf-incident-time">{{ relativeTime(incident) }}</span>
                </component>
            </div>
            <div class="lf-insight-empty" v-else>No failures, long waits, paused supervisors, or telemetry incidents in this view.</div>
        </section>

        <section class="lf-pane lf-insight-card lf-insight-tags">
            <div class="lf-pane-head">
                <span class="lf-pane-title">Monitored tags</span>
                <router-link class="lf-pane-link" :to="{ name: 'monitoring' }">Manage</router-link>
            </div>
            <div class="lf-tag-grid" v-if="monitoredTags.length">
                <router-link
                    class="lf-monitor-tag"
                    v-for="tag in monitoredTags"
                    :key="tag.tag"
                    :to="{ name: 'monitoring-jobs', params: { tag: tag.tag } }"
                >
                    <span class="lf-monitor-tag-name">{{ tag.tag }}</span>
                    <span class="lf-monitor-tag-count">{{ formatCount(tag.count) }} jobs</span>
                </router-link>
            </div>
            <div class="lf-insight-empty" v-else>
                No tags are being monitored.
                <router-link :to="{ name: 'monitoring' }">Start monitoring a tag</router-link>.
            </div>
        </section>

        <section class="lf-pane lf-insight-card lf-insight-batches">
            <div class="lf-pane-head">
                <span class="lf-pane-title">Recent batches</span>
                <router-link class="lf-pane-link" :to="{ name: 'batches' }">View all</router-link>
            </div>
            <div class="lf-batch-list" v-if="batchesReady && batches.length">
                <div class="lf-batch-row" v-for="batch in batches" :key="batch.id">
                    <router-link class="lf-batch-main" :to="{ name: 'batches-preview', params: { batchId: batch.id } }">
                        <strong>{{ batch.name || batch.id }}</strong>
                        <span class="lf-batch-progress" aria-hidden="true"><span :style="{ width: `${Math.max(0, Math.min(100, Number(batch.progress ?? 0)))}%` }"></span></span>
                        <small>{{ formatNumber(batch.totalJobs) }} jobs · {{ formatNumber(batch.progress) }}%</small>
                    </router-link>
                    <span class="lf-batch-state" :class="'lf-batch-state-' + batchTone(batch)">{{ batchStatus(batch) }}</span>
                    <button
                        v-if="Number(batch.failedJobs ?? 0) > 0"
                        class="lf-mini-btn"
                        type="button"
                        :disabled="retryingBatches.includes(batch.id)"
                        @click="retryBatch(batch)"
                    >{{ retryingBatches.includes(batch.id) ? 'Retrying' : 'Retry failed' }}</button>
                </div>
            </div>
            <div class="lf-insight-empty" v-else-if="!batchesReady">Loading recent batches…</div>
            <div class="lf-insight-empty" v-else-if="batchError">Batch data is unavailable.</div>
            <div class="lf-insight-empty" v-else>No batches were found.</div>
        </section>
    </div>
</template>
