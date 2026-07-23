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

        data() {
            return {
                sortKey: null,
                sortDir: 1,
            };
        },

        computed: {
            columns() {
                return [
                    { key: 'name',       label: 'Queue',      string: true },
                    { key: 'source',     label: 'Src',        string: true },
                    { key: 'connection', label: 'Connection', string: true },
                    { key: 'driver',     label: 'Driver',     string: true },
                    { key: 'pending',    label: 'Pending' },
                    { key: 'delayed',    label: 'Delayed' },
                    { key: 'oldest',     label: 'Oldest' },
                    { key: 'wait',       label: 'Wait' },
                    { key: 'procs',      label: 'Procs' },
                    { key: 'current',    label: 'Current' },
                    { key: 'last',       label: 'Last' },
                    { key: 'eta',        label: 'ETA' },
                    { key: 'attempts',   label: 'Attempts' },
                    { key: 'failed',     label: 'Failed' },
                    { key: 'failrate',   label: 'Fail %' },
                    { key: 'status',     label: 'Status' },
                ];
            },

            sortedQueues() {
                if (!this.sortKey) return this.queues;

                const dir = this.sortDir;
                const key = this.sortKey;

                return [...this.queues].sort((a, b) => {
                    const va = this.sortValue(a, key);
                    const vb = this.sortValue(b, key);
                    return (typeof va === 'string' ? va.localeCompare(vb) : va - vb) * dir;
                });
            },

            queueHealthCounts() {
                return this.queues.reduce((counts, queue) => {
                    const status = this.queueStatus(queue);
                    counts[status] = (counts[status] ?? 0) + 1;
                    return counts;
                }, { healthy: 0, warning: 0, critical: 0 });
            },
        },

        methods: {
            resolveId(queue) {
                return this.findQueueNode(queue)?.id ?? this.queueNodeId(queue);
            },

            sortBy(column) {
                if (this.sortKey === column.key) {
                    this.sortDir = -this.sortDir;
                    return;
                }
                this.sortKey = column.key;
                // Strings read naturally ascending; metrics are more useful
                // worst-first.
                this.sortDir = column.string ? 1 : -1;
            },

            sortValue(queue, key) {
                switch (key) {
                    case 'name':       return String(queue.name ?? '');
                    case 'source':     return String(queue.source ?? queue.driver ?? '');
                    case 'connection': return String(queue.connection ?? '');
                    case 'driver':     return String(queue.driver ?? '');
                    case 'pending':    return Number(queue.pending ?? 0);
                    case 'delayed':    return Number(queue.delayed ?? 0);
                    case 'oldest':     return Number(queue.oldest_pending_seconds ?? queue.wait_seconds ?? 0);
                    case 'wait':       return Number(queue.wait_seconds ?? 0);
                    case 'procs':      return Number(queue.processes ?? 0);
                    case 'current':    return Number(queue.current_throughput_per_minute ?? 0);
                    case 'last':       return Number(queue.throughput_per_minute ?? 0);
                    case 'eta':        return Number(queue.estimated_drain_seconds ?? 0);
                    case 'attempts':   return Number(queue.attempts ?? 0);
                    case 'failed':     return this.queueFailedInWindow(queue);
                    case 'failrate':   return Number(queue.failure_rate ?? 0);
                    case 'status':     return { healthy: 0, warning: 1, critical: 2 }[this.queueStatus(queue)] ?? 0;
                    default:           return 0;
                }
            },

            sortArrow(column) {
                if (this.sortKey !== column.key) return '';
                return this.sortDir === 1 ? '▲' : '▼';
            },

            sortAria(column) {
                if (this.sortKey !== column.key) return 'none';
                return this.sortDir === 1 ? 'ascending' : 'descending';
            },
        },
    };
</script>

<template>
    <div class="lf-pane lf-pane-gap">
        <div class="lf-pane-head">
            <span class="lf-pane-title">Queues</span>
            <span class="lf-pane-meta">{{ queues.length }} queue{{ queues.length === 1 ? '' : 's' }}</span>
            <div class="lf-queue-summary" aria-label="Queue health summary">
                <span class="lf-queue-summary-item lf-queue-summary-healthy">{{ queueHealthCounts.healthy }} operational</span>
                <span class="lf-queue-summary-item lf-queue-summary-warning">{{ queueHealthCounts.warning }} attention</span>
                <span class="lf-queue-summary-item lf-queue-summary-critical">{{ queueHealthCounts.critical }} critical</span>
            </div>
        </div>
        <div class="lf-tbl-wrap">
            <table class="lf-tbl">
                <thead>
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            class="sortable"
                            :class="{ r: !column.string, 'lf-th-active': sortKey === column.key }"
                            :aria-sort="sortAria(column)"
                        ><button class="lf-sort-btn" type="button" @click="sortBy(column)">{{ column.label }}<span class="lf-th-arrow" v-if="sortKey === column.key">{{ sortArrow(column) }}</span></button></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="queue in sortedQueues"
                        :key="queue.driver + ':' + queue.connection + ':' + queue.name"
                        :class="{ 'lf-tbl-sel': selectedId === resolveId(queue) }"
                        tabindex="0"
                        @click="$emit('select', resolveId(queue))"
                        @keydown.enter="$emit('select', resolveId(queue))"
                    >
                        <td><span class="lf-qname">{{ queue.name }}</span></td>
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
