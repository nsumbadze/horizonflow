<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            queues: { type: Array, default: () => [] },
            selectedId: { type: String, default: null },
            filterText: { type: String, default: '' },
            queueNodeId: { type: Function, required: true },
            findQueueNode: { type: Function, required: true },
            // Parent-provided so the table rates queues with the same
            // windowed failed count the graph uses — otherwise the two
            // disagree whenever old failures fall outside the window.
            queueStatus: { type: Function, required: true },
            queueFailedInWindow: { type: Function, required: true },
        },

        emits: ['select'],

        methods: {
            resolveId(queue) {
                return this.findQueueNode(queue)?.id ?? this.queueNodeId(queue);
            },
        },
    };
</script>

<template>
    <div class="lf-pane lf-pane-gap">
        <div class="lf-pane-head">
            <span class="lf-pane-title">Queues</span>
            <span class="lf-pane-meta">{{ queues.length }} queue{{ queues.length === 1 ? '' : 's' }}</span>
        </div>
        <div class="lf-tbl-wrap">
            <table class="lf-tbl">
                <thead>
                    <tr>
                        <th>Queue</th><th>Src</th><th>Connection</th><th>Driver</th>
                        <th class="r">Pending</th><th class="r">Delayed</th><th class="r">Oldest</th><th class="r">Wait</th>
                        <th class="r">Procs</th><th class="r">Current</th><th class="r">Last</th><th class="r">ETA</th>
                        <th class="r">Attempts</th><th class="r">Failed</th><th class="r">Fail %</th><th class="r">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="queue in queues"
                        :key="queue.driver + ':' + queue.connection + ':' + queue.name"
                        :class="{ 'lf-tbl-sel': selectedId === resolveId(queue) }"
                        @click="$emit('select', resolveId(queue))"
                    >
                        <td><span class="lf-qname"><span class="lf-dot" :class="'lf-dot-' + queueStatus(queue)"></span>{{ queue.name }}</span></td>
                        <td class="muted">{{ queue.source ?? queue.driver }}</td>
                        <td class="muted">{{ queue.connection }}</td>
                        <td><span class="lf-drv" :class="'lf-drv-' + queue.driver">{{ queue.driver }}</span></td>
                        <td class="r num" :class="{ warn: queue.pending > 100, crit: queue.pending > 500 }">{{ formatNumber(queue.pending) }}</td>
                        <td class="r num muted">{{ formatNumber(queue.delayed) }}</td>
                        <td class="r num" :class="{ warn: (queue.oldest_pending_seconds ?? 0) >= 10, crit: (queue.oldest_pending_seconds ?? 0) >= 30 }">{{ formatDuration(queue.oldest_pending_seconds ?? queue.wait_seconds) }}</td>
                        <td class="r num" :class="{ warn: queue.wait_seconds >= 10, crit: queue.wait_seconds >= 30 }">{{ metricValue(queue.wait_seconds, 's') }}</td>
                        <td class="r num muted">{{ formatNumber(queue.processes) }}</td>
                        <td class="r num ok">{{ formatRate(queue.current_throughput_per_minute) }}</td>
                        <td class="r num muted">{{ formatRate(queue.throughput_per_minute) }}</td>
                        <td class="r num muted">{{ formatDuration(queue.estimated_drain_seconds) }}</td>
                        <td class="r num muted">{{ formatNumber(queue.attempts ?? 0) }}</td>
                        <td class="r num" :class="{ crit: queueFailedInWindow(queue) > 0 }" :title="formatNumber(queue.failed ?? 0) + ' all-time'">{{ formatNumber(queueFailedInWindow(queue)) }}</td>
                        <td class="r num" :class="{ warn: (queue.failure_rate ?? 0) > 0 }">{{ formatPercent(queue.failure_rate) }}</td>
                        <td class="r">
                            <span class="lf-status" :class="'lf-status-' + queueStatus(queue)">{{ statusLabel(queueStatus(queue)) }}</span>
                        </td>
                    </tr>
                    <tr v-if="queues.length === 0">
                        <td colspan="16" class="lf-empty">{{ filterText ? 'No queues match the filter.' : 'No queues found.' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
