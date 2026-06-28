<script type="text/ecmascript-6">
    import formatters from './formatters';
    import Sparkline from './Sparkline.vue';

    export default {
        components: { Sparkline },

        mixins: [formatters],

        props: {
            inspector: { type: Object, required: true },
            graphNodeLookup: { type: Object, default: () => ({}) },
            retryingIds: { type: Array, default: () => [] },
        },

        emits: ['retry', 'open-failed'],

        computed: {
            // Snapshot series recorded by horizon:snapshot (~1/min). Rows are
            // hidden until at least two points exist so a fresh install
            // doesn't render empty charts.
            trendRows() {
                const snapshots = this.inspector.snapshots ?? [];
                if (snapshots.length < 2) return [];

                const series = (key) => snapshots.map(s => Number(s?.[key] ?? 0));
                const latest = (values) => values[values.length - 1];

                const throughput = series('throughput');
                const runtime = series('runtime');
                const wait = series('wait');

                return [
                    { key: 'throughput', label: 'throughput', values: throughput, latest: this.formatRate(latest(throughput)), color: 'var(--lf-cyan)' },
                    { key: 'runtime',    label: 'runtime',    values: runtime,    latest: this.metricValue(latest(runtime), 's'), color: 'var(--lf-violet)' },
                    { key: 'wait',       label: 'wait',       values: wait,       latest: this.metricValue(latest(wait), 's'), color: 'var(--lf-amber)' },
                ];
            },
        },

        methods: {
            isRetrying(job) {
                return this.retryingIds.includes(job?.id);
            },

            edgeDisplayLabel(edge) {
                const rate = Number(edge.rate_per_minute ?? 0);
                if (rate <= 0) return ['dispatch', 'reserve', 'finish'].includes(edge.label) ? 'idle' : edge.label;
                return this.formatRate(rate);
            },
        },
    };
</script>

<template>
    <aside class="lf-inspector">
        <div class="lf-pane lf-pane-sticky">
            <div class="lf-pane-head">
                <span class="lf-pane-title">Inspector</span>
                <span class="lf-status ms-auto" :class="'lf-status-' + inspector.node.status">{{ statusLabel(inspector.node.status) }}</span>
            </div>

            <div class="lf-insp-top">
                <div class="lf-insp-name">{{ inspector.node.label }}</div>
                <div class="lf-insp-meta">
                    <span class="lf-insp-kind">{{ nodeKind(inspector.node) }}</span>
                    <span class="lf-insp-conn">{{ inspector.queue ? inspector.queue.connection + ' · ' + inspector.queue.name : inspector.node.id }}</span>
                </div>
            </div>

            <div class="lf-insp-sec">
                <div class="lf-insp-sec-title">Metrics</div>
                <div class="lf-kv" v-for="m in inspector.metrics" :key="m[0]">
                    <span class="lf-kv-k">{{ m[0] }}</span>
                    <span class="lf-kv-v">{{ m[1] }}</span>
                </div>
            </div>

            <div class="lf-insp-sec" v-if="inspector.queue && trendRows.length">
                <div class="lf-insp-sec-title">Trends</div>
                <div class="lf-trend" v-for="trend in trendRows" :key="trend.key">
                    <span class="lf-trend-label">{{ trend.label }}</span>
                    <Sparkline class="lf-trend-spark" :values="trend.values" :color="trend.color" :height="18"/>
                    <span class="lf-trend-latest">{{ trend.latest }}</span>
                </div>
            </div>

            <div class="lf-insp-sec" v-if="inspector.jobClass">
                <div class="lf-insp-sec-title">Class Breakdown</div>
                <div class="lf-job-class">
                    <div>
                        <div class="lf-job-name">{{ shortJobName(inspector.jobClass.name) }}</div>
                        <div class="lf-job-sub">{{ jobCounts(inspector.jobClass) }}</div>
                    </div>
                    <span class="lf-job-fail" v-if="Number(inspector.jobClass.failed ?? 0) > 0">{{ formatNumber(inspector.jobClass.failed) }} failed</span>
                </div>
            </div>

            <div class="lf-insp-sec" v-if="inspector.queue && !inspector.jobClass">
                <div class="lf-insp-sec-title">Job Classes</div>
                <div class="lf-job-class" v-for="jobClass in inspector.jobClasses" :key="jobClass.name">
                    <div>
                        <div class="lf-job-name">{{ shortJobName(jobClass.name) }}</div>
                        <div class="lf-job-sub">{{ jobCounts(jobClass) }}</div>
                    </div>
                    <span class="lf-job-fail" v-if="Number(jobClass.failed ?? 0) > 0">{{ formatNumber(jobClass.failed) }} failed</span>
                </div>
                <div class="lf-empty-sm" v-if="inspector.jobClasses.length === 0">No recent job classes for this queue.</div>
            </div>

            <div class="lf-insp-sec" v-if="inspector.queue">
                <div class="lf-insp-sec-title">Recent Jobs</div>
                <div
                    class="lf-job-row"
                    v-for="job in inspector.jobs"
                    :key="job.id"
                    :class="{ 'lf-job-row-clickable': job.status === 'failed' }"
                    @click="$emit('open-failed', job)"
                >
                    <span class="lf-dot" :class="'lf-dot-' + jobStatusClass(job.status)"></span>
                    <div class="lf-job-main">
                        <div class="lf-job-name">{{ shortJobName(job.name) }}</div>
                        <div class="lf-job-sub">
                            {{ job.status }} · attempts {{ formatNumber(job.attempts ?? 0) }} · age {{ formatDuration(job.age_seconds) }}
                        </div>
                        <div class="lf-job-error" v-if="job.exception">{{ job.exception }}</div>
                    </div>
                    <button
                        class="lf-mini-btn"
                        type="button"
                        v-if="job.retryable"
                        :disabled="isRetrying(job)"
                        @click.stop="$emit('retry', job)"
                    >
                        {{ isRetrying(job) ? 'retrying' : 'retry' }}
                    </button>
                </div>
                <div class="lf-empty-sm" v-if="inspector.jobs.length === 0">No recent jobs captured for this queue.</div>
            </div>

            <div class="lf-insp-sec">
                <div class="lf-insp-sec-title">Incoming</div>
                <div class="lf-edge-row" v-for="edge in inspector.incoming" :key="edge.id">
                    <span class="lf-dot" :class="'lf-dot-' + edge.status"></span>
                    <span class="lf-edge-lbl">{{ graphNodeLookup[edge.source]?.label ?? edge.source }}</span>
                    <span class="lf-edge-rate">{{ edgeDisplayLabel(edge) }}</span>
                </div>
                <div class="lf-empty-sm" v-if="inspector.incoming.length === 0">—</div>
            </div>

            <div class="lf-insp-sec">
                <div class="lf-insp-sec-title">Outgoing</div>
                <div class="lf-edge-row" v-for="edge in inspector.outgoing" :key="edge.id">
                    <span class="lf-dot" :class="'lf-dot-' + edge.status"></span>
                    <span class="lf-edge-lbl">{{ graphNodeLookup[edge.target]?.label ?? edge.target }}</span>
                    <span class="lf-edge-rate">{{ edgeDisplayLabel(edge) }}</span>
                </div>
                <div class="lf-empty-sm" v-if="inspector.outgoing.length === 0">—</div>
            </div>

            <div class="lf-action" :class="'lf-action-' + inspector.action.type">
                <div class="lf-action-title">{{ inspector.action.title }}</div>
                <div class="lf-action-text">{{ inspector.action.text }}</div>
                <router-link
                    v-if="inspector.action.link"
                    class="lf-action-link"
                    :to="inspector.action.link.to"
                >{{ inspector.action.link.text }}</router-link>
            </div>
        </div>
    </aside>
</template>
