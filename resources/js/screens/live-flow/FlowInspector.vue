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
            controllingJobIds: { type: Array, default: () => [] },
            queueControlling: { type: Boolean, default: false },
            nodes: { type: Array, default: () => [] },
            selectedId: { type: String, default: null },
            mode: { type: String, default: 'graph' },
        },

        emits: ['retry', 'cancel-job', 'pause-queue', 'resume-queue', 'open-failed', 'open-graph', 'open-activity', 'select'],

        computed: {
            nodeGroups() {
                return ['producer', 'queue', 'job', 'worker', 'result']
                    .map(type => ({
                        type,
                        label: type === 'result' ? 'Results' : `${type.charAt(0).toUpperCase()}${type.slice(1)}s`,
                        nodes: this.nodes.filter(node => node.type === type),
                    }))
                    .filter(group => group.nodes.length > 0);
            },

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

            visibleJobs() {
                return this.inspector.jobs.slice(0, 5);
            },
        },

        methods: {
            isRetrying(job) {
                return this.retryingIds.includes(job?.id);
            },

            isControllingJob(job) {
                return this.controllingJobIds.includes(job?.id);
            },

            edgeDisplayLabel(edge) {
                const rate = Number(edge.rate_per_minute ?? 0);
                if (rate <= 0) return ['dispatch', 'reserve', 'finish'].includes(edge.label) ? 'idle' : edge.label;
                return this.formatRate(rate);
            },

            stateLabel(state) {
                return {
                    reserved: 'Running',
                    cancellation_requested: 'Cancel requested',
                    cancelled: 'Cancelled',
                    completed: 'Succeeded',
                }[this.jobStateKey(state)] ?? state;
            },

            isLongMetric(metric) {
                return String(metric?.[0] ?? '').includes('error') || String(metric?.[1] ?? '').length > 72;
            },

            metricDetail(value) {
                const text = String(value ?? '');
                const separator = text.indexOf(': ');

                if (separator < 1) {
                    return { type: null, fullType: null, message: text };
                }

                const fullType = text.slice(0, separator);

                return {
                    type: fullType.split('\\').pop(),
                    fullType,
                    message: text.slice(separator + 2),
                };
            },
        },
    };
</script>

<template>
    <aside class="lf-inspector">
        <div class="lf-pane">
            <div class="lf-pane-head">
                <span class="lf-pane-title">Inspector</span>
                <label class="lf-inspector-picker">
                    <span class="visually-hidden">Choose inspector node</span>
                    <select :value="selectedId ?? ''" @change="$emit('select', $event.target.value || null)">
                        <option value="">Choose a node…</option>
                        <optgroup v-for="group in nodeGroups" :key="group.type" :label="group.label">
                            <option v-for="node in group.nodes" :key="node.id" :value="node.id">{{ node.label }}</option>
                        </optgroup>
                    </select>
                </label>
                <span v-if="!inspector.empty" class="lf-status" :class="'lf-status-' + inspector.node.status">{{ statusLabel(inspector.node.status) }}</span>
            </div>

            <div class="lf-inspector-empty" v-if="inspector.empty">
                <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <rect x="4" y="6" width="10" height="8" rx="2" stroke="currentColor" stroke-width="1.5"/>
                    <rect x="18" y="18" width="10" height="8" rx="2" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M14 10h3a5 5 0 015 5v3M17 15l5 3 3-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <div class="lf-inspector-empty-title">Nothing selected</div>
                <div class="lf-inspector-empty-text" v-if="mode === 'graph'">Choose a producer, queue, job, worker, or result on the graph—or use the menu above—to inspect its metrics and recent activity.</div>
                <div class="lf-inspector-empty-text" v-else>Select a queue in the table, choose any node from the menu above, or switch to the graph for the complete flow.</div>
                <button v-if="mode !== 'graph'" class="lf-control-btn lf-control-btn-primary" type="button" @click="$emit('open-graph')">Switch to graph</button>
            </div>

            <template v-else>
            <div class="lf-insp-top">
                <div class="lf-insp-title-row">
                    <div class="lf-insp-name">{{ inspector.node.label }}</div>
                    <span class="lf-jstate lf-jstate-cancellation_requested" v-if="inspector.queue?.paused">Paused</span>
                </div>
                <div class="lf-insp-meta">
                    <span class="lf-insp-kind">{{ nodeKind(inspector.node) }}</span>
                    <span class="lf-insp-conn">{{ inspector.queue ? inspector.queue.connection + ' · ' + inspector.queue.name : inspector.node.id }}</span>
                </div>
                <div class="lf-insp-controls" v-if="inspector.queue?.driver === 'redis'">
                    <button
                        v-if="!inspector.queue.paused"
                        class="lf-mini-btn lf-mini-btn-danger"
                        type="button"
                        :disabled="queueControlling"
                        @click="$emit('pause-queue', inspector.queue)"
                    >{{ queueControlling ? 'pausing…' : 'pause queue' }}</button>
                    <button
                        v-else
                        class="lf-mini-btn lf-mini-btn-safe"
                        type="button"
                        :disabled="queueControlling"
                        @click="$emit('resume-queue', inspector.queue)"
                    >{{ queueControlling ? 'resuming…' : 'resume queue' }}</button>
                    <span class="lf-insp-control-help">
                        {{ inspector.queue.source === 'mock'
                            ? 'Demo only; Redis and workers are never changed.'
                            : (inspector.queue.paused ? 'Jobs remain queued until resumed.' : 'Current work finishes; no new jobs will start.') }}
                    </span>
                </div>
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

            <div class="lf-insp-sec lf-insp-sec-metrics">
                <div class="lf-insp-sec-title">Metrics</div>
                <div class="lf-kv" v-for="m in inspector.metrics" :key="m[0]">
                    <span class="lf-kv-k">{{ m[0] }}</span>
                    <details class="lf-kv-detail" v-if="isLongMetric(m)">
                        <summary>View details</summary>
                        <div class="lf-kv-detail-body">
                            <strong v-if="metricDetail(m[1]).type" :title="metricDetail(m[1]).fullType">{{ metricDetail(m[1]).type }}</strong>
                            <span>{{ metricDetail(m[1]).message }}</span>
                        </div>
                    </details>
                    <span class="lf-kv-v" v-else>{{ m[1] }}</span>
                </div>
            </div>

            <div class="lf-insp-sec lf-insp-sec-trends" v-if="inspector.queue && trendRows.length">
                <div class="lf-insp-sec-title">Trends</div>
                <div class="lf-trend" v-for="trend in trendRows" :key="trend.key">
                    <span class="lf-trend-label">{{ trend.label }}</span>
                    <Sparkline class="lf-trend-spark" :values="trend.values" :color="trend.color" :height="18"/>
                    <span class="lf-trend-latest">{{ trend.latest }}</span>
                </div>
            </div>

            <div class="lf-insp-sec lf-insp-sec-classes" v-if="inspector.jobClass">
                <div class="lf-insp-sec-title">Class Breakdown</div>
                <div class="lf-job-class">
                    <div>
                        <div class="lf-job-name">{{ shortJobName(inspector.jobClass.name) }}</div>
                        <div class="lf-job-sub">{{ jobCounts(inspector.jobClass) }}</div>
                    </div>
                    <span class="lf-job-fail" v-if="Number(inspector.jobClass.failed ?? 0) > 0">{{ formatNumber(inspector.jobClass.failed) }} failed</span>
                </div>
            </div>

            <div class="lf-insp-sec lf-insp-sec-classes" v-if="inspector.queue && !inspector.jobClass">
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

            <div class="lf-insp-sec lf-insp-sec-jobs" v-if="inspector.queue">
                <div class="lf-insp-sec-heading">
                    <div class="lf-insp-sec-title">Recent Jobs</div>
                    <div class="lf-insp-sec-actions">
                        <span>Latest {{ Math.min(5, inspector.jobs.length) }} of {{ inspector.jobs.length }}</span>
                        <button type="button" v-if="inspector.jobs.length > 5" @click="$emit('open-activity')">Activity →</button>
                    </div>
                </div>
                <div
                    class="lf-job-row"
                    v-for="job in visibleJobs"
                    :key="job.id"
                >
                    <div class="lf-job-main">
                        <div class="lf-job-name">{{ shortJobName(job.name) }}</div>
                        <div class="lf-job-sub">
                            <span class="lf-jstate" :class="'lf-jstate-' + jobStateKey(job.status)">{{ stateLabel(job.status) }}</span>
                            <span class="lf-job-meta">attempts {{ formatNumber(job.attempts ?? 0) }} · age {{ formatDuration(job.age_seconds) }}</span>
                        </div>
                        <div class="lf-job-error" v-if="job.exception" :title="job.exception">{{ job.exception }}</div>
                    </div>
                    <div class="lf-job-actions" v-if="job.status === 'failed' || job.cancellable">
                        <button class="lf-mini-btn" type="button" v-if="job.status === 'failed'" @click="$emit('open-failed', job)">View</button>
                        <button
                            class="lf-mini-btn"
                            type="button"
                            v-if="job.retryable"
                            :disabled="isRetrying(job)"
                            @click="$emit('retry', job)"
                        >{{ isRetrying(job) ? 'retrying' : 'retry' }}</button>
                        <button
                            class="lf-mini-btn lf-mini-btn-danger"
                            type="button"
                            v-if="job.cancellable && job.status !== 'cancellation_requested'"
                            :disabled="isControllingJob(job)"
                            @click="$emit('cancel-job', job)"
                        >{{ isControllingJob(job) ? 'working…' : (job.status === 'pending' ? 'cancel' : 'request cancel') }}</button>
                        <button
                            class="lf-mini-btn"
                            type="button"
                            v-else-if="job.status === 'cancellation_requested'"
                            disabled
                        >requested</button>
                    </div>
                </div>
                <div class="lf-empty-sm" v-if="inspector.jobs.length === 0">No recent jobs captured for this queue.</div>
            </div>

            <div class="lf-insp-connections">
                <div class="lf-insp-sec lf-insp-sec-incoming">
                    <div class="lf-insp-sec-title">Incoming</div>
                    <div class="lf-edge-row" v-for="edge in inspector.incoming" :key="edge.id">
                        <span class="lf-edge-lbl">{{ graphNodeLookup[edge.source]?.label ?? edge.source }}</span>
                        <span class="lf-edge-rate" :class="'lf-st-' + edge.status">{{ edgeDisplayLabel(edge) }}</span>
                    </div>
                    <div class="lf-empty-sm" v-if="inspector.incoming.length === 0">—</div>
                </div>

                <div class="lf-insp-sec lf-insp-sec-outgoing">
                    <div class="lf-insp-sec-title">Outgoing</div>
                    <div class="lf-edge-row" v-for="edge in inspector.outgoing" :key="edge.id">
                        <span class="lf-edge-lbl">{{ graphNodeLookup[edge.target]?.label ?? edge.target }}</span>
                        <span class="lf-edge-rate" :class="'lf-st-' + edge.status">{{ edgeDisplayLabel(edge) }}</span>
                    </div>
                    <div class="lf-empty-sm" v-if="inspector.outgoing.length === 0">—</div>
                </div>
            </div>
            </template>
        </div>
    </aside>
</template>
