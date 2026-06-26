/**
 * Shared display helpers for the live-flow screen. Mixed into every
 * live-flow component so number/duration/status formatting stays
 * identical across the graph, table, inspector, and modals.
 */
export default {
    methods: {
        formatNumber(value) {
            if (value === null || value === undefined) return '—';
            if (typeof value === 'number' && !Number.isInteger(value)) return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
            return Number(value).toLocaleString();
        },

        metricValue(value, suffix = '') {
            if (value === null || value === undefined) return '—';
            return this.formatNumber(value) + suffix;
        },

        formatRate(value) {
            return (value === null || value === undefined) ? '—' : `${this.formatNumber(value)}/m`;
        },

        formatPercent(value) {
            if (value === null || value === undefined) return '—';
            return `${this.formatNumber(value)}%`;
        },

        formatCount(value) {
            const n = Number(value ?? 0);
            if (n >= 100000) return `${(n / 1000).toFixed(0)}k`;
            if (n >= 10000) return `${(n / 1000).toFixed(1)}k`;
            return n.toLocaleString();
        },

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

        shortJobName(name) {
            return String(name ?? 'Queued job').split('\\').pop();
        },

        statusLabel(status) {
            return { healthy: 'healthy', warning: 'warn', critical: 'critical' }[status] ?? status;
        },

        jobStatusClass(status) {
            return {
                failed: 'critical',
                reserved: 'warning',
                pending: 'warning',
                completed: 'healthy',
            }[status] ?? 'healthy';
        },

        jobCounts(jobClass) {
            const parts = [
                ['pending', jobClass.pending],
                ['reserved', jobClass.reserved],
                ['ok', jobClass.completed],
                ['failed', jobClass.failed],
            ].filter(([, value]) => Number(value ?? 0) > 0);

            return parts.length
                ? parts.map(([label, value]) => `${this.formatNumber(value)} ${label}`).join(' · ')
                : 'no recent jobs';
        },

        nodeKind(node) {
            if (!node) return '';
            return { producer: 'PRODUCER', queue: 'QUEUE', job: 'JOB', worker: 'WORKER', result: node.label?.toUpperCase?.() ?? 'RESULT' }[node.type] ?? node.type?.toUpperCase?.();
        },

        svgId(value) {
            return String(value).replace(/[^a-z0-9_-]+/gi, '-');
        },
    },
};
