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

        /**
         * Wall-clock date for a unix timestamp, in the viewer's own timezone.
         * Live-flow shows absolute times rather than "11s ago" so a row still
         * means something once the poll has moved on or the tab was left open.
         */
        clockDate(timestamp) {
            const ts = Number(timestamp ?? 0);
            if (!ts) return '—';

            const date = new Date(ts * 1000);
            const pad = value => String(value).padStart(2, '0');

            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        },

        clockTime(timestamp) {
            const ts = Number(timestamp ?? 0);
            if (!ts) return '—';

            const date = new Date(ts * 1000);
            const pad = value => String(value).padStart(2, '0');

            return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
        },

        absoluteTime(timestamp) {
            const ts = Number(timestamp ?? 0);
            if (!ts) return '—';

            return `${this.clockDate(ts)} ${this.clockTime(ts)}`;
        },

        /**
         * Absolute timestamp with the relative age appended, for `title`
         * tooltips where "how long ago" is still the quicker read.
         */
        absoluteTimeWithAge(timestamp) {
            const ts = Number(timestamp ?? 0);
            if (!ts) return 'No timestamp recorded';

            const relative = this.relativeTime({ timestamp: ts });

            return `${this.absoluteTime(ts)} (${relative === 'now' ? 'just now' : relative + ' ago'})`;
        },

        shortJobName(name) {
            return String(name ?? 'Queued job').split('\\').pop();
        },

        statusLabel(status) {
            return { healthy: 'healthy', warning: 'warn', critical: 'critical' }[status] ?? status;
        },

        /**
         * Severity of a job state, for anything that rolls up into queue
         * health. Use jobStateKey() instead when showing the state itself.
         */
        jobStatusClass(status) {
            return {
                failed: 'critical',
                reserved: 'warning',
                cancellation_requested: 'warning',
                pending: 'warning',
                cancelled: 'healthy',
                completed: 'healthy',
            }[status] ?? 'healthy';
        },

        /**
         * Lifecycle state of a single job. This is a category, not a severity:
         * completed and failed are both "finished" and must stay tellable
         * apart at a glance, so each state keeps its own colour.
         */
        jobStateKey(state) {
            const key = String(state ?? '').toLowerCase();
            return ['pending', 'reserved', 'cancellation_requested', 'cancelled', 'completed', 'failed'].includes(key) ? key : 'pending';
        },

        jobCounts(jobClass) {
            const parts = [
                ['pending', jobClass.pending],
                ['reserved', jobClass.reserved],
                ['cancel requested', jobClass.cancellation_requested],
                ['cancelled', jobClass.cancelled],
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
