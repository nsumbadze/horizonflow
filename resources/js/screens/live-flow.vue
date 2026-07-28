<script type="text/ecmascript-6">
    import FailedJobModal from './live-flow/FailedJobModal.vue';
    import FlowActivity from './live-flow/FlowActivity.vue';
    import FlowGraph from './live-flow/FlowGraph.vue';
    import FlowInsights from './live-flow/FlowInsights.vue';
    import FlowInspector from './live-flow/FlowInspector.vue';
    import FlowKpis from './live-flow/FlowKpis.vue';
    import FlowQueueTable from './live-flow/FlowQueueTable.vue';
    import FlowToolbar from './live-flow/FlowToolbar.vue';
    import SupervisorControls from './live-flow/SupervisorControls.vue';
    import formatters from './live-flow/formatters';
    import { loadViewState, saveViewState } from './live-flow/viewState';

    const TIME_RANGES = ['Last 5m', 'Last 15m', 'Last 1h', 'Last 6h', 'Last 24h', 'Last 3d', 'Last 7d', 'Last 30d'];
    const RANGE_SLUGS = {
        'Last 5m': '5m', 'Last 15m': '15m', 'Last 1h': '1h', 'Last 6h': '6h',
        'Last 24h': '24h', 'Last 3d': '3d', 'Last 7d': '7d', 'Last 30d': '30d',
    };
    const SLUG_RANGES = Object.fromEntries(Object.entries(RANGE_SLUGS).map(([range, slug]) => [slug, range]));
    const WORKSPACE_TABS = ['flow', 'activity', 'insights', 'controls'];
    const FLOW_MODES = ['graph', 'queues'];

    function normalizedWorkspace(view) {
        if (['graph', 'queues', 'inspector'].includes(view)) return 'flow';
        return WORKSPACE_TABS.includes(view) ? view : null;
    }

    export default {
        components: { FailedJobModal, FlowActivity, FlowGraph, FlowInsights, FlowInspector, FlowKpis, FlowQueueTable, FlowToolbar, SupervisorControls },

        mixins: [formatters],

        data() {
            const saved = loadViewState();
            const query = this.$route?.query ?? {};
            const queryRange = SLUG_RANGES[String(query.window ?? '')];
            const queryView = String(query.view ?? '');
            const queryTab = normalizedWorkspace(queryView);
            const savedTab = normalizedWorkspace(saved.activeWorkspaceTab);
            const queryMode = String(query.mode ?? '');
            const legacyMode = FLOW_MODES.includes(queryView) ? queryView : null;

            return {
                flow: null,
                ready: false,
                refreshing: false,
                live: true,
                filterText: typeof query.q === 'string' ? query.q : (typeof saved.filterText === 'string' ? saved.filterText : ''),
                timeRange: queryRange ?? (TIME_RANGES.includes(saved.timeRange) ? saved.timeRange : 'Last 15m'),
                activeWorkspaceTab: queryTab ?? savedTab ?? 'flow',
                flowMode: FLOW_MODES.includes(queryMode) ? queryMode : (legacyMode ?? (FLOW_MODES.includes(saved.flowMode) ? saved.flowMode : 'graph')),
                selectedId: typeof query.node === 'string' && query.node !== '' ? query.node : null,
                isDark: this.sniffDark(),
                retryingJobs: [],
                controllingJobs: [],
                controllingQueues: [],
                selectedJob: null,
                selectedJobDetails: null,
                loadingJobDetails: false,
                masters: [],
                controllingHorizon: [],
                lastEventTimestamp: 0,
                queueJobDetails: {},
                flowCounts: { dispatched: 0, reserved: 0, completed: 0, failed: 0 },
                zoom: Number.isFinite(saved.zoom) ? Math.min(2.5, Math.max(0.35, saved.zoom)) : 1,
                queueSnapshots: {},
                comparisonSummary: null,
                monitoredTags: [],
                persistentIncidents: [],
                controlConfirmation: null,
                controlNotice: null,
                controlHistory: [],
            };
        },

        watch: {
            selectedId(id) {
                const node = this.graphNodeLookup[id];
                if (node?.type === 'queue') {
                    const queue = this.queues.find(q => this.queueNodeId(q) === node.id || this.findQueueNode(q)?.id === node.id);
                    if (queue) {
                        this.fetchQueueJobs(queue);
                        this.fetchQueueSnapshots(queue);
                    }
                }
                this.syncRouteState();
            },

            timeRange(value) {
                // Reset the events cursor so the next /events poll fetches the
                // freshly-scoped window. Drop the rendered events too — they were
                // tied to the prior window.
                this.lastEventTimestamp = Math.max(0, Math.floor(Date.now() / 1000) - this.timeRangeSeconds());
                this.mergeFlow({ events: [] });
                this.refreshSummary();
                this.refreshComparison();
                this.refreshGraph();
                this.refreshQueues();
                saveViewState({ timeRange: value });
                this.syncRouteState();
            },

            filterText(value) {
                saveViewState({ filterText: value });
                this.syncRouteState();
            },

            zoom(value) {
                saveViewState({ zoom: value });
            },

            activeWorkspaceTab(value) {
                saveViewState({ activeWorkspaceTab: value });
                this.syncRouteState();
            },

            flowMode(value) {
                saveViewState({ flowMode: value });
                this.syncRouteState();
            },

            '$route.query': {
                deep: true,
                handler(query) {
                    this.applyRouteQuery(query);
                },
            },
        },

        mounted() {
            document.title = "Horizon - Live Flow";
            this.refreshAll().then(() => { this.ready = true; });
            this.loadSupervisorControls();
            this.loadMonitoredTags();
            this.loadIncidents();
            this.startPolling();
            this.syncRouteState();
            this.isDark = this.sniffDark();
            this.initDarkWatcher();
            // Catch up immediately when the tab becomes visible again. The
            // bootstrap flag is cleared so the backlog of events fetched after
            // a long hidden stretch doesn't inflate the session counters.
            this._visibilityHandler = () => {
                if (document.hidden || !this.live) return;
                this._eventsBootstrapped = false;
                this.refreshAll();
            };
            document.addEventListener('visibilitychange', this._visibilityHandler);
        },

        beforeUnmount() {
            this.stopPolling();
            this._darkObserver?.disconnect();
            this._mq?.removeEventListener('change', this._mqUpdate);
            if (this._storageHandler) window.removeEventListener('storage', this._storageHandler);
            if (this._visibilityHandler) document.removeEventListener('visibilitychange', this._visibilityHandler);
            if (this._noticeTimeout) clearTimeout(this._noticeTimeout);
        },

        computed: {
            summary()  { return this.flow?.summary ?? {}; },
            meta()     { return this.flow?.meta ?? {}; },
            appLabel() { return this.meta.app_name ?? this.meta.horizon_name ?? 'Laravel application'; },

            generatedAt() {
                if (!this.flow?.generated_at) return null;
                return new Date(this.flow.generated_at).toLocaleTimeString();
            },

            sourceClass() {
                return { mock: 'mock', redis: 'redis', database: 'db', auto: 'auto' }[this.flow?.source] ?? 'mock';
            },

            sourceLabel() {
                if (this.flow?.source === 'auto') {
                    return `unified · ${(this.flow?.sources ?? []).join(' + ') || 'live'}`;
                }
                return { mock: 'mock · demo', redis: 'redis · live', database: 'db · live' }[this.flow?.source] ?? (this.flow ? this.flow.source : 'loading');
            },

            isMock()  { return this.flow?.source === 'mock'; },
            queues()  { return this.flow?.queues ?? []; },
            health()  { return this.flow?.health ?? []; },

            workspaceTabs() {
                return [
                    { key: 'flow', label: 'Flow', count: this.filteredQueues.length },
                    { key: 'activity', label: 'Activity', count: (this.flow?.events ?? []).length },
                    { key: 'insights', label: 'Insights', count: this.incidentTimeline.length },
                    { key: 'controls', label: 'Horizon controls', count: this.supervisors.length },
                ];
            },

            healthBanner() {
                const failed = this.health.filter(h => h.status === 'failed');
                if (failed.length === 0) return null;
                const names = failed.map(h => h.source).join(', ');
                const detail = failed.map(h => h.message).filter(Boolean).join(' · ');
                return detail ? `${names}: ${detail}` : `${names} unreachable`;
            },

            supervisors() {
                return (this.masters ?? []).flatMap(master => (master.supervisors ?? []).map(supervisor => ({
                    ...supervisor,
                    master: master.name,
                })));
            },

            filteredQueues() {
                const f = this.filterText.trim().toLowerCase();
                if (!f) return this.queues;
                return this.queues.filter(q =>
                    [q.name, q.connection, q.storage_connection, q.driver, q.source].filter(Boolean).some(v => String(v).toLowerCase().includes(f))
                );
            },

            svgHeight() {
                const nodeH = 52, gap = 22, topPad = 44, botPad = 32;
                const queueCount  = Math.max(1, this.filteredQueues.length);
                const jobCount = Math.max(1, this.queueJobNodes().length);
                const workerCount = Math.max(1, (this.flow?.nodes ?? []).filter(n => n.type === 'worker').slice(0, 4).length);
                const resultCount = Math.max(1, (this.flow?.nodes ?? []).filter(n => n.type === 'result').slice(0, 4).length);
                const maxCol = Math.max(queueCount, jobCount, workerCount, resultCount);
                return Math.max(620, topPad + maxCol * (nodeH + gap) - gap + botPad);
            },

            graphNodes() {
                const H = this.svgHeight;
                const topPad = 44, botPad = 32;
                const qH = 50, jH = 46, wH = 46, rH = 50, pH = 52;
                const qYMin = topPad,     qYMax = H - qH - botPad;
                const jYMin = topPad + 2, jYMax = H - jH - botPad - 2;
                const wYMin = topPad + 4, wYMax = H - wH - botPad - 4;
                const rYMin = topPad,     rYMax = H - rH - botPad;
                const midY = H / 2;

                const queues = this.filteredQueues.map((queue, i) => {
                    return {
                        id: this.queueGraphId(queue),
                        type: 'queue', label: queue.name, sub: this.queueSubLabel(queue),
                        status: this.queueStatus(queue),
                        x: 205, y: this.distributedY(i, this.filteredQueues.length, qYMin, qYMax),
                        width: 128, height: qH,
                        metrics: {
                            pending: queue.pending, delayed: queue.delayed,
                            wait: queue.wait_seconds, processes: queue.processes,
                            throughput: queue.throughput_per_minute,
                            current_throughput: queue.current_throughput_per_minute,
                            failed: queue.failed,
                        },
                    };
                });

                const jobSources = this.queueJobNodes();
                const jobNodes = jobSources.map((job, i, all) => ({
                    id: job.id,
                    type: 'job',
                    label: this.shortJobName(job.name),
                    sub: this.jobNodeSub(job),
                    status: this.jobNodeStatus(job),
                    queueId: job.queueId,
                    name: job.name,
                    x: 395,
                    y: this.distributedY(i, all.length || 1, jYMin, jYMax),
                    width: 134,
                    height: jH,
                    metrics: job,
                }));

                const workerList = (this.flow?.nodes ?? []).filter(n => n.type === 'worker').slice(0, 4);
                const workers = workerList.map((n, i, all) => ({
                    id: n.id, type: 'worker', label: n.label,
                    sub: `${this.formatNumber(n.metrics?.processes ?? this.summary.processing)} processes`,
                    status: n.status,
                    x: 590, y: this.distributedY(i, all.length || 1, wYMin, wYMax),
                    width: 128, height: wH, metrics: n.metrics ?? {},
                }));
                const workerNodes = workers.length ? workers : [{
                    id: 'workers', type: 'worker', label: 'workers',
                    sub: `${this.formatNumber(this.summary.processing)} active`,
                    status: 'healthy', x: 590, y: midY - wH / 2, width: 128, height: wH,
                    metrics: { processes: this.summary.processing },
                }];

                const resultList = (this.flow?.nodes ?? []).filter(n => n.type === 'result').slice(0, 4);
                const results = resultList.map((n, i, all) => ({
                    id: n.id, type: 'result', label: n.label,
                    sub: this.resultSubLabel(n), status: n.status,
                    x: 790, y: this.distributedY(i, all.length || 1, rYMin, rYMax),
                    width: 132, height: rH, metrics: n.metrics ?? {},
                }));

                const prodSpread = Math.min(120, H * 0.16);
                const baseNodes = [
                    {
                        id: 'producer-app', type: 'producer', label: this.appLabel,
                        sub: `${this.meta.environment ?? 'app'} · ${this.formatNumber(this.summary.current_throughput_per_minute ?? this.summary.throughput_per_minute)}/m`,
                        status: 'healthy', x: 28, y: Math.round(midY - prodSpread - pH / 2), width: 136, height: pH,
                        metrics: { throughput: this.summary.throughput_per_minute, current_throughput: this.summary.current_throughput_per_minute },
                    },
                    {
                        id: 'producer-scheduler', type: 'producer', label: 'scheduler',
                        sub: `${this.formatNumber(this.summary.delayed)} delayed`,
                        status: this.summary.delayed > 0 ? 'warning' : 'healthy',
                        x: 28, y: Math.round(midY + prodSpread - pH / 2), width: 136, height: pH,
                        metrics: { delayed: this.summary.delayed },
                    },
                    ...queues, ...jobNodes, ...workerNodes, ...results,
                ];
                return baseNodes;
            },

            graphNodeLookup() {
                return this.graphNodes.reduce((acc, n) => { acc[n.id] = n; return acc; }, {});
            },

            graphEdges() {
                const existing = (this.flow?.edges ?? []).filter(e => this.graphNodeLookup[e.source] && this.graphNodeLookup[e.target]);
                const jobNodes = this.graphNodes.filter(n => n.type === 'job');
                if (existing.length && jobNodes.length === 0) return existing;

                const workers   = this.graphNodes.filter(n => n.type === 'worker');
                const results   = this.graphNodes.filter(n => n.type === 'result');
                const completed = results.find(n => n.label === 'completed') ?? results[0];
                const failed    = results.find(n => n.label === 'failed');
                const generated = [];

                this.graphNodes.filter(n => n.type === 'queue').forEach((q, i) => {
                    const w = workers[i % workers.length];
                    const producer = (q.status === 'critical' || q.status === 'warning') ? 'producer-scheduler' : 'producer-app';
                    const queueJobs = jobNodes.filter(j => j.queueId === q.id);

                    generated.push(this.edge(producer, q.id, q.status, 'dispatch', q.metrics.current_throughput ?? q.metrics.throughput));

                    if (queueJobs.length === 0) {
                        generated.push(this.edge(q.id, w.id, q.status, 'reserve', q.metrics.current_throughput ?? q.metrics.throughput));
                        return;
                    }

                    queueJobs.forEach(job => {
                        generated.push(this.edge(q.id, job.id, job.status, 'jobs', this.jobNodeFlow(job)));
                        generated.push(this.edge(job.id, w.id, job.status, 'reserve', this.jobNodeFlow(job)));
                        if (completed && Number(job.metrics.completed ?? 0) > 0) generated.push(this.edge(job.id, completed.id, 'healthy', 'done', job.metrics.completed));
                        if (failed && Number(job.metrics.failed ?? 0) > 0) generated.push(this.edge(job.id, failed.id, 'critical', 'failed', job.metrics.failed));
                    });
                });
                if (completed) workers.forEach(w => generated.push(this.edge(w.id, completed.id, 'healthy', 'finish', this.summary.throughput_per_minute)));
                // Always keep a worker→failed edge so a brand-new failure event
                // has a path to animate along, even before the next /summary
                // refresh has incremented summary.failed. Edge stays "idle" when
                // there are no failures (rate=0 ⇒ rendered as "idle" label).
                if (failed && workers.length) {
                    const failedRate = Number(this.summary.failed_in_window ?? this.summary.failed ?? 0);
                    const status = failedRate > 0 ? 'critical' : 'healthy';
                    generated.push(this.edge(workers[workers.length - 1].id, failed.id, status, 'exception', failedRate));
                }
                return generated;
            },

            kpiMetrics() {
                const windowed = this.summary.failed_in_window;
                const failedValue = windowed !== null && windowed !== undefined ? windowed : this.summary.failed;
                const failedSub = windowed !== null && windowed !== undefined
                    ? `in ${this.timeRange.replace(/^Last /i, '').toLowerCase()}`
                    : 'all-time';

                return [
                    { key: 'pending', label: 'PENDING', value: this.metricValue(this.summary.pending), sub: this.formatNumber(this.queues.length) + ' queues', cls: 'primary', to: { name: 'jobs', params: { type: 'pending' } } },
                    { key: 'processing', label: 'WORKERS', value: this.metricValue(this.summary.processing), sub: 'active', cls: '', tab: 'controls' },
                    { key: 'delayed', label: 'DELAYED', value: this.metricValue(this.summary.delayed), sub: 'scheduled', cls: (this.summary.delayed ?? 0) > 0 ? 'warn' : '', tab: 'flow', mode: 'queues' },
                    { key: 'failed', label: 'FAILED', value: this.metricValue(failedValue), sub: failedSub, cls: (failedValue ?? 0) > 0 ? 'danger' : '', to: { name: 'failed-jobs' } },
                    { key: 'flow', label: 'THROUGHPUT', value: this.metricValue(this.summary.current_throughput_per_minute ?? this.summary.throughput_per_minute), sub: 'jobs / min', cls: 'ok', to: { name: 'metrics-queues' } },
                    { key: 'wait', label: 'AVG WAIT', value: this.metricValue(this.summary.average_wait_seconds, 's'), sub: 'latency', cls: '', to: { name: 'metrics-queues' } },
                ];
            },

            selectedNode() {
                return this.selectedId ? (this.graphNodeLookup[this.selectedId] ?? null) : null;
            },

            selectedInspector() {
                const node = this.selectedNode;
                if (!node) return { empty: true, node: { status: 'healthy' }, queue: null, jobClass: null, metrics: [], jobClasses: [], jobs: [], incoming: [], outgoing: [], action: null };

                let queue = null;
                let jobClass = null;

                if (node.type === 'job') {
                    queue = this.queues.find(q => this.queueGraphId(q) === node.queueId) ?? null;
                    if (queue) {
                        jobClass = this.queueJobClasses(queue).find(c => c.name === node.name) ?? null;
                    }
                } else {
                    queue = this.queues.find(q => this.queueNodeId(q) === node.id || this.findQueueNode(q)?.id === node.id) ?? null;
                }

                return {
                    node,
                    queue,
                    jobClass,
                    metrics: this.inspectorMetrics(node, queue, jobClass),
                    jobClasses: queue && !jobClass ? this.queueJobClasses(queue) : [],
                    jobs: queue ? this.queueJobs(queue).filter(job => !jobClass || job.name === jobClass.name) : [],
                    snapshots: queue ? (this.queueSnapshots[queue.name]?.snapshots ?? []) : [],
                    incoming: this.graphEdges.filter(e => e.target === node.id),
                    outgoing: this.graphEdges.filter(e => e.source === node.id),
                    action: this.suggestedAction(node, queue, jobClass),
                };
            },

            comparisonMetrics() {
                const expanded = this.comparisonSummary ?? {};
                const currentFailed = Number(this.summary.failed_in_window ?? this.summary.failed ?? 0);
                const currentCompleted = Number(this.summary.completed_in_window ?? this.summary.completed ?? 0);
                const previousFailed = expanded.failed_in_window === undefined
                    ? null
                    : Math.max(0, Number(expanded.failed_in_window) - currentFailed);
                const previousCompleted = expanded.completed_in_window === undefined
                    ? null
                    : Math.max(0, Number(expanded.completed_in_window) - currentCompleted);

                return [
                    this.comparisonMetric('completed', 'Succeeded jobs', currentCompleted, previousCompleted, false, 'previous window'),
                    this.comparisonMetric('failed', 'Failed jobs', currentFailed, previousFailed, true, 'previous window'),
                    this.comparisonMetric(
                        'throughput',
                        'Throughput',
                        Number(this.summary.current_throughput_per_minute ?? 0),
                        Number(this.summary.throughput_per_minute ?? 0),
                        false,
                        'last snapshot',
                        '/m'
                    ),
                ];
            },

            incidentTimeline() {
                const now = Math.floor(Date.now() / 1000);
                const failures = (this.flow?.events ?? [])
                    .filter(event => event.state === 'failed')
                    .map(event => ({
                        id: `failure-${event.id ?? event.timestamp}`,
                        timestamp: Number(event.timestamp ?? now),
                        severity: 'critical',
                        title: `${this.shortJobName(event.job)} failed`,
                        detail: event.queue ? `Queue ${event.queue}` : event.label,
                        to: /^[0-9a-f]{8}-[0-9a-f-]{27}$/i.test(String(event.id ?? ''))
                            ? { name: 'failed-jobs-preview', params: { jobId: event.id } }
                            : { name: 'failed-jobs' },
                    }));
                const waits = this.queues
                    .filter(queue => Number(queue.wait_seconds ?? 0) >= 10)
                    .map(queue => ({
                        id: `wait-${queue.connection}-${queue.name}`,
                        timestamp: now,
                        severity: Number(queue.wait_seconds) >= 30 ? 'critical' : 'warning',
                        title: `Long wait on ${queue.name}`,
                        detail: `${this.formatDuration(queue.wait_seconds)} wait · ${this.formatNumber(queue.pending)} pending`,
                        tab: 'flow',
                        mode: 'queues',
                    }));
                const supervisors = this.supervisors
                    .filter(supervisor => supervisor.status !== 'running')
                    .map(supervisor => ({
                        id: `supervisor-${supervisor.name}`,
                        timestamp: now,
                        severity: supervisor.status === 'inactive' ? 'critical' : 'warning',
                        title: `${supervisor.name} is ${supervisor.status}`,
                        detail: supervisor.master ?? 'Horizon supervisor',
                        tab: 'controls',
                    }));
                const health = this.health
                    .filter(source => source.status === 'failed')
                    .map(source => ({
                        id: `source-${source.source}`,
                        timestamp: now,
                        severity: 'critical',
                        title: `${source.source} telemetry unavailable`,
                        detail: source.message ?? 'Source connection failed',
                    }));

                const persisted = this.persistentIncidents.map(incident => ({
                    ...incident,
                    to: /^[0-9a-f]{8}-[0-9a-f-]{27}$/i.test(String(incident.job_id ?? ''))
                        ? { name: 'failed-jobs-preview', params: { jobId: incident.job_id } }
                        : undefined,
                }));

                return [...this.controlHistory, ...persisted, ...failures, ...waits, ...supervisors, ...health]
                    .filter((incident, index, all) => all.findIndex(item => `${item.title}|${item.detail}` === `${incident.title}|${incident.detail}`) === index)
                    .sort((a, b) => b.timestamp - a.timestamp)
                    .slice(0, 20);
            },
        },

        methods: {
            // The dark-mode signal is the same one Horizon's SchemeToggler controls:
            // the `media` attribute on the `style[data-scheme="dark"]` stylesheet is
            // empty when dark is active. We mirror its localStorage key so a manual
            // theme choice is respected immediately.
            sniffDark() {
                try {
                    const stored = localStorage.getItem('horizonColorScheme');
                    if (stored === 'dark') return true;
                    if (stored === 'light') return false;
                    return window.matchMedia('(prefers-color-scheme: dark)').matches;
                } catch { return false; }
            },

            initDarkWatcher() {
                // Same-tab toggle: SchemeToggler mutates the stylesheet directly,
                // so observe its `media` attribute for instant updates.
                const el = document.querySelector('style[data-scheme="dark"]');
                if (el) {
                    this._darkObserver = new MutationObserver(() => { this.isDark = this.sniffDark(); });
                    this._darkObserver.observe(el, { attributes: true, attributeFilter: ['media'] });
                }
                // Cross-tab toggle: storage event fires only in OTHER tabs.
                this._storageHandler = (event) => {
                    if (event.key === 'horizonColorScheme') this.isDark = this.sniffDark();
                };
                window.addEventListener('storage', this._storageHandler);
                // System preference change when scheme is 'system'.
                this._mq = window.matchMedia('(prefers-color-scheme: dark)');
                this._mqUpdate = () => { this.isDark = this.sniffDark(); };
                this._mq.addEventListener('change', this._mqUpdate);
            },

            timeRangeSeconds() {
                return {
                    'Last 5m':   300,
                    'Last 15m':  900,
                    'Last 1h':   3600,
                    'Last 6h':   21600,
                    'Last 24h':  86400,
                    'Last 3d':   259200,
                    'Last 7d':   604800,
                    'Last 30d':  2592000,
                }[this.timeRange] ?? 900;
            },

            applyRouteQuery(query) {
                const routeView = String(query.view ?? '');
                const routeTab = normalizedWorkspace(routeView);
                const routeMode = String(query.mode ?? '');
                const routeRange = SLUG_RANGES[String(query.window ?? '')];
                const routeFilter = typeof query.q === 'string' ? query.q : '';
                const routeNode = typeof query.node === 'string' && query.node !== '' ? query.node : null;

                if (routeTab && routeTab !== this.activeWorkspaceTab) this.activeWorkspaceTab = routeTab;
                if (FLOW_MODES.includes(routeMode) && routeMode !== this.flowMode) this.flowMode = routeMode;
                else if (FLOW_MODES.includes(routeView) && routeView !== this.flowMode) this.flowMode = routeView;
                if (routeRange && routeRange !== this.timeRange) this.timeRange = routeRange;
                if (routeFilter !== this.filterText) this.filterText = routeFilter;
                if (routeNode !== this.selectedId) this.selectedId = routeNode;
            },

            syncRouteState() {
                if (!this.$router || !this.$route) return;

                const query = { ...this.$route.query };
                query.view = this.activeWorkspaceTab;
                query.window = RANGE_SLUGS[this.timeRange] ?? '15m';

                if (this.activeWorkspaceTab === 'flow') query.mode = this.flowMode;
                else delete query.mode;

                if (this.filterText.trim() !== '') query.q = this.filterText;
                else delete query.q;

                if (this.selectedId) query.node = this.selectedId;
                else delete query.node;

                const currentKeys = Object.keys(this.$route.query).sort();
                const nextKeys = Object.keys(query).sort();
                const isSame = currentKeys.length === nextKeys.length
                    && nextKeys.every(key => String(this.$route.query[key]) === String(query[key]));

                if (!isSame) this.$router.replace({ query }).catch(() => {});
            },

            comparisonMetric(key, label, current, previous, lowerIsBetter, referenceLabel, suffix = '') {
                if (previous === null || previous === undefined) {
                    return { key, label, current: `${this.formatNumber(current)}${suffix}`, previous: '—', delta: 'No baseline', tone: 'neutral', referenceLabel };
                }

                const difference = current - previous;
                const percent = previous === 0 ? (current === 0 ? 0 : 100) : Math.round((difference / previous) * 100);
                const improved = lowerIsBetter ? difference < 0 : difference > 0;
                const worsened = lowerIsBetter ? difference > 0 : difference < 0;

                return {
                    key,
                    label,
                    current: `${this.formatNumber(current)}${suffix}`,
                    previous: `${this.formatNumber(previous)}${suffix}`,
                    delta: `${difference > 0 ? '+' : ''}${percent}%`,
                    tone: improved ? 'good' : (worsened ? 'bad' : 'neutral'),
                    referenceLabel,
                };
            },

            startPolling() {
                // document.hidden guard: no point polling a tab nobody is
                // looking at — refreshAll() on visibilitychange catches up.
                this._intervals = [
                    setInterval(() => this.shouldPoll() && this.refreshSummary(),  5000),
                    setInterval(() => this.shouldPoll() && this.refreshGraph(),   10000),
                    setInterval(() => this.shouldPoll() && this.refreshQueues(),  10000),
                    setInterval(() => this.shouldPoll() && this.refreshEvents(),   2000),
                    setInterval(() => this.shouldPoll() && this.refreshComparison(), 15000),
                    setInterval(() => this.shouldPoll() && this.loadMonitoredTags(), 30000),
                    setInterval(() => this.shouldPoll() && this.loadIncidents(), 10000),
                ];
            },

            shouldPoll() {
                return this.live && !document.hidden;
            },

            stopPolling() {
                (this._intervals ?? []).forEach(clearInterval);
                this._intervals = [];
            },

            refreshAll() {
                this.refreshing = true;
                return Promise.all([
                    this.refreshSummary(),
                    this.refreshComparison(),
                    this.refreshGraph(),
                    this.refreshQueues(),
                    this.refreshEvents(),
                ]).finally(() => { this.refreshing = false; });
            },

            refreshFlowPeriodically() {
                return this.refreshAll();
            },

            mergeFlow(slice) {
                this.flow = { ...(this.flow ?? {}), ...slice };
            },

            refreshSummary() {
                return this.$http.get(Horizon.basePath + '/api/flow/summary', {
                    params: { window: this.timeRangeSeconds() },
                })
                    .then(response => {
                        this.mergeFlow({
                            source: response.data.source,
                            sources: response.data.sources,
                            errors: response.data.errors ?? [],
                            health: response.data.health ?? [],
                            meta: response.data.meta ?? {},
                            generated_at: response.data.generated_at,
                            summary: response.data.summary ?? {},
                        });
                    })
                    .catch(() => {});
            },

            refreshComparison() {
                if (this.timeRangeSeconds() >= 2592000) {
                    this.comparisonSummary = null;
                    return Promise.resolve();
                }

                return this.$http.get(Horizon.basePath + '/api/flow/summary', {
                    params: { window: this.timeRangeSeconds() * 2 },
                })
                    .then(response => { this.comparisonSummary = response.data.summary ?? {}; })
                    .catch(() => { this.comparisonSummary = null; });
            },

            refreshGraph() {
                return this.$http.get(Horizon.basePath + '/api/flow/graph', {
                    params: { window: this.timeRangeSeconds() },
                })
                    .then(response => this.mergeFlow({
                        nodes: response.data.nodes ?? [],
                        edges: response.data.edges ?? [],
                    }))
                    .catch(() => {});
            },

            refreshQueues() {
                return this.$http.get(Horizon.basePath + '/api/flow/queues', {
                    params: { window: this.timeRangeSeconds() },
                })
                    .then(response => {
                        this.mergeFlow({ queues: response.data.queues ?? [] });
                        const selected = this.selectedQueue();
                        if (selected) {
                            this.fetchQueueJobs(selected);
                            this.fetchQueueSnapshots(selected);
                        }
                    })
                    .catch(() => {});
            },

            refreshEvents() {
                // Forward the active window so this hits the same cache slot as
                // summary/graph/queues; otherwise the events poll lives in the
                // default-window slot and forces a parallel payload rebuild.
                const params = { window: this.timeRangeSeconds() };
                if (this.lastEventTimestamp > 0) params.since = this.lastEventTimestamp;

                return this.$http.get(Horizon.basePath + '/api/flow/events', { params }).then(response => {
                    const fresh = response.data.events ?? [];
                    if (fresh.length === 0) return;

                    const existing = this.flow?.events ?? [];
                    const seen = new Set();
                    const merged = [...fresh, ...existing]
                        .filter(event => {
                            const key = event.id ?? event.label ?? `${event.timestamp}-${event.queue}`;
                            if (seen.has(key)) return false;
                            seen.add(key);
                            return true;
                        })
                        .slice(0, 60);

                    this.mergeFlow({ events: merged });
                    this.lastEventTimestamp = fresh.reduce(
                        (max, event) => Math.max(max, Number(event.timestamp ?? 0)),
                        this.lastEventTimestamp
                    );

                    // First poll returns historical events — skip them so the
                    // session counters don't jump by N on page load. Subsequent
                    // polls are real-time deltas, one flow-count bump each.
                    if (this._eventsBootstrapped) {
                        fresh.forEach(event => this.bumpFlowCount(event));
                    }
                    this._eventsBootstrapped = true;
                }).catch(() => {});
            },

            bumpFlowCount(event) {
                const map = {
                    completed: 'completed',
                    failed: 'failed',
                    workers: 'reserved',
                    queue: 'dispatched',
                };
                const key = map[event.result];
                if (key) this.flowCounts[key]++;
            },

            loadSupervisorControls() {
                return this.$http.get(Horizon.basePath + '/api/masters')
                    .then(response => { this.masters = Object.values(response.data ?? {}); })
                    .catch(() => { this.masters = []; });
            },

            loadMonitoredTags() {
                return this.$http.get(Horizon.basePath + '/api/monitoring')
                    .then(response => { this.monitoredTags = Array.isArray(response.data) ? response.data : []; })
                    .catch(() => { this.monitoredTags = []; });
            },

            loadIncidents() {
                return this.$http.get(Horizon.basePath + '/api/flow/incidents', { params: { limit: 50 } })
                    .then(response => { this.persistentIncidents = response.data.incidents ?? []; })
                    .catch(() => { this.persistentIncidents = []; });
            },

            isControllingHorizon(key) {
                return this.controllingHorizon.includes(key);
            },

            requestMastersAction(action) {
                if (action !== 'pause') {
                    return this.controlMasters(action);
                }

                this.controlConfirmation = {
                    kind: 'masters',
                    action,
                    title: 'Pause all Horizon supervisors?',
                    text: 'Queued jobs will remain in place, but no new work will be processed until the supervisors are resumed.',
                    confirmLabel: 'Pause all',
                };
            },

            cancelControlConfirmation() {
                this.controlConfirmation = null;
            },

            confirmControlAction() {
                const confirmation = this.controlConfirmation;
                this.controlConfirmation = null;
                if (!confirmation) return;

                if (confirmation.kind === 'masters') {
                    this.controlMasters(confirmation.action);
                } else if (confirmation.kind === 'queue') {
                    this.controlQueue(confirmation.queue, confirmation.action);
                } else if (confirmation.kind === 'job') {
                    this.controlJob(confirmation.job);
                }
            },

            showControlNotice(type, message) {
                this.controlNotice = { type, message };
                if (this._noticeTimeout) clearTimeout(this._noticeTimeout);
                this._noticeTimeout = setTimeout(() => { this.controlNotice = null; }, 5000);
            },

            recordControlEvent(id, title, detail) {
                this.controlHistory = [{
                    id: `${id}-${Date.now()}`,
                    timestamp: Math.floor(Date.now() / 1000),
                    severity: 'info',
                    title,
                    detail,
                    tab: 'controls',
                }, ...this.controlHistory].slice(0, 10);
            },

            controlMasters(action) {
                const key = `masters:${action}`;
                if (this.isControllingHorizon(key)) return;

                this.controllingHorizon = [...this.controllingHorizon, key];

                return this.$http.post(`${Horizon.basePath}/api/masters/${action}`)
                    .then(() => Promise.all([this.loadSupervisorControls(), this.refreshFlowPeriodically()]))
                    .then(() => {
                        const label = action === 'pause' ? 'paused' : 'resumed';
                        this.recordControlEvent(`masters-${action}`, `All supervisors ${label}`, 'Horizon control action completed in this session.');
                        this.showControlNotice('success', `All Horizon supervisors were ${label}.`);
                    })
                    .catch(() => {
                        this.showControlNotice('error', `Horizon could not ${action === 'pause' ? 'pause' : 'resume'} all supervisors.`);
                    })
                    .finally(() => {
                        this.controllingHorizon = this.controllingHorizon.filter(item => item !== key);
                    });
            },

            controlSupervisor(supervisor, action) {
                const key = `${supervisor.name}:${action}`;
                if (this.isControllingHorizon(key)) return;

                this.controllingHorizon = [...this.controllingHorizon, key];

                return this.$http.post(`${Horizon.basePath}/api/supervisors/${encodeURIComponent(supervisor.name)}/${action}`)
                    .then(() => Promise.all([this.loadSupervisorControls(), this.refreshFlowPeriodically()]))
                    .then(() => {
                        const label = action === 'pause' ? 'paused' : 'resumed';
                        this.recordControlEvent(`supervisor-${supervisor.name}-${action}`, `${supervisor.name} ${label}`, 'Horizon supervisor action completed in this session.');
                        this.showControlNotice('success', `${supervisor.name} was ${label}.`);
                    })
                    .catch(() => {
                        this.showControlNotice('error', `${supervisor.name} could not be ${action === 'pause' ? 'paused' : 'resumed'}.`);
                    })
                    .finally(() => {
                        this.controllingHorizon = this.controllingHorizon.filter(item => item !== key);
                    });
            },

            queueControlKey(queue) {
                return `${queue?.storage_connection ?? queue?.connection}:${queue?.name}`;
            },

            isControllingQueue(queue) {
                return this.controllingQueues.includes(this.queueControlKey(queue));
            },

            requestQueuePause(queue) {
                if (!queue || this.isControllingQueue(queue)) return;

                this.controlConfirmation = {
                    kind: 'queue',
                    action: 'pause',
                    queue,
                    title: `Pause ${queue.name}?`,
                    text: 'Any job already running will finish. Pending and newly dispatched jobs will remain queued and no new jobs will start until this queue is resumed.',
                    confirmLabel: 'Pause queue',
                    tone: 'warning',
                };
            },

            controlQueue(queue, action) {
                if (!queue || !['pause', 'resume'].includes(action)) return;

                const key = this.queueControlKey(queue);
                if (this.controllingQueues.includes(key)) return;
                this.controllingQueues = [...this.controllingQueues, key];

                const body = {
                    connection: queue.storage_connection ?? queue.connection,
                    queue: queue.name,
                };

                return this.$http.post(`${Horizon.basePath}/api/flow/queues/${action}`, body)
                    .then(() => {
                        const paused = action === 'pause';
                        this.mergeFlow({
                            queues: this.queues.map(item => this.queueControlKey(item) === key
                                ? { ...item, paused }
                                : item),
                        });
                        this.showControlNotice('success', `${queue.name} was ${paused ? 'paused' : 'resumed'}.`);
                        this.recordControlEvent(
                            `queue-${queue.name}-${action}`,
                            `${queue.name} ${paused ? 'paused' : 'resumed'}`,
                            paused ? 'Queued jobs are being retained until this queue is resumed.' : 'Workers may reserve new jobs again.'
                        );
                        setTimeout(() => this.refreshFlowPeriodically(), 1200);
                    })
                    .catch(error => {
                        const message = error?.response?.data?.message;
                        this.showControlNotice('error', message || `${queue.name} could not be ${action === 'pause' ? 'paused' : 'resumed'}.`);
                    })
                    .finally(() => {
                        this.controllingQueues = this.controllingQueues.filter(item => item !== key);
                    });
            },

            requestJobCancellation(job) {
                if (!job?.id || this.controllingJobs.includes(job.id)) return;

                const pending = job.status === 'pending';
                this.controlConfirmation = {
                    kind: 'job',
                    job,
                    title: pending ? `Cancel ${this.shortJobName(job.name)}?` : `Request cancellation of ${this.shortJobName(job.name)}?`,
                    text: pending
                        ? 'If the job is still waiting, it will be atomically removed and retained as cancelled. If a worker reserves it first, this becomes a cooperative cancellation request.'
                        : 'The worker will not be killed. The job will stop only at a cancellation checkpoint; side effects already performed cannot be undone.',
                    confirmLabel: pending ? 'Cancel job' : 'Request cancellation',
                    tone: 'danger',
                };
            },

            controlJob(job) {
                if (!job?.id || this.controllingJobs.includes(job.id)) return;
                this.controllingJobs = [...this.controllingJobs, job.id];

                return this.$http.post(`${Horizon.basePath}/api/jobs/${encodeURIComponent(job.id)}/cancel`)
                    .then(response => {
                        const action = response.data?.action;
                        const status = action === 'cancelled' ? 'cancelled' : 'cancellation_requested';
                        this.replaceQueueJob(job.id, {
                            ...response.data,
                            status,
                            cancellable: status === 'cancellation_requested',
                        });
                        this.showControlNotice(
                            'success',
                            action === 'cancelled'
                                ? `${this.shortJobName(job.name)} was cancelled before it started.`
                                : `Cancellation was requested for ${this.shortJobName(job.name)}.`
                        );
                        setTimeout(() => this.refreshFlowPeriodically(), 1200);
                    })
                    .catch(error => {
                        const message = error?.response?.data?.message;
                        this.showControlNotice('error', message || `${this.shortJobName(job.name)} could not be cancelled.`);
                    })
                    .finally(() => {
                        this.controllingJobs = this.controllingJobs.filter(id => id !== job.id);
                    });
            },

            replaceQueueJob(id, changes) {
                this.queueJobDetails = Object.fromEntries(
                    Object.entries(this.queueJobDetails).map(([key, detail]) => [
                        key,
                        {
                            ...detail,
                            jobs: (detail.jobs ?? []).map(job => job.id === id ? { ...job, ...changes } : job),
                        },
                    ])
                );
            },

            selectNode(id) { this.selectedId = id; },
            navigateFromMetric(destination) {
                if (destination) this.navigateWorkspace(destination);
            },
            navigateWorkspace(destination) {
                const tab = typeof destination === 'string' ? destination : destination.tab;
                const mode = typeof destination === 'object' ? destination.mode : null;
                if (FLOW_MODES.includes(mode)) this.flowMode = mode;
                this.selectWorkspaceTab(tab);
            },
            selectWorkspaceTab(tab) {
                const workspace = normalizedWorkspace(tab);
                if (!workspace) return;
                if (FLOW_MODES.includes(tab)) this.flowMode = tab;
                this.activeWorkspaceTab = workspace;
            },
            moveWorkspaceTab(tab, direction) {
                const currentIndex = WORKSPACE_TABS.indexOf(tab);
                const nextIndex = (currentIndex + direction + WORKSPACE_TABS.length) % WORKSPACE_TABS.length;
                this.activeWorkspaceTab = WORKSPACE_TABS[nextIndex];
                this.$nextTick(() => document.getElementById(`lf-tab-${this.activeWorkspaceTab}`)?.focus());
            },
            toggleLive()   { this.live = !this.live; },

            queueJobsKey(queue) {
                if (!queue) return null;
                if (Array.isArray(queue.drivers) && queue.drivers.length > 1) return queue.name;
                return `${queue.driver}:${queue.connection}:${queue.name}`;
            },

            queueJobDetail(queue) {
                const key = this.queueJobsKey(queue);
                return key ? this.queueJobDetails[key] : null;
            },

            queueJobClasses(queue) {
                const detail = this.queueJobDetail(queue);
                const classes = detail?.job_classes ?? queue?.job_classes ?? [];
                return classes.slice(0, 8);
            },

            queueJobs(queue) {
                const detail = this.queueJobDetail(queue);
                const jobs = detail?.jobs ?? queue?.jobs ?? [];
                return jobs.slice(0, 12);
            },

            // Horizon's own metrics endpoint serves the snapshot series the
            // scheduler's horizon:snapshot command records (~1/min, 24 kept).
            // A short TTL keeps re-selections from hammering it.
            fetchQueueSnapshots(queue) {
                const name = queue?.name;
                if (!name) return Promise.resolve();

                const entry = this.queueSnapshots[name];
                if (entry && Date.now() - entry.fetchedAt < 60000) return Promise.resolve();

                this.queueSnapshots = {
                    ...this.queueSnapshots,
                    [name]: { fetchedAt: Date.now(), snapshots: entry?.snapshots ?? [] },
                };

                return this.$http.get(Horizon.basePath + '/api/metrics/queues/' + encodeURIComponent(name))
                    .then(response => {
                        this.queueSnapshots = {
                            ...this.queueSnapshots,
                            [name]: { fetchedAt: Date.now(), snapshots: Array.isArray(response.data) ? response.data : [] },
                        };
                    })
                    .catch(() => {});
            },

            fetchQueueJobs(queue) {
                const key = this.queueJobsKey(queue);
                if (!key) return Promise.resolve();

                return this.$http.get(Horizon.basePath + '/api/flow/queue-jobs', { params: { key } })
                    .then(response => {
                        this.queueJobDetails = {
                            ...this.queueJobDetails,
                            [key]: {
                                jobs: response.data.jobs ?? [],
                                job_classes: response.data.job_classes ?? [],
                            },
                        };
                    })
                    .catch(() => {});
            },

            selectedQueue() {
                const node = this.selectedNode;
                if (!node || node.type !== 'queue') return null;
                return this.queues.find(q => this.queueNodeId(q) === node.id || this.findQueueNode(q)?.id === node.id) ?? null;
            },

            queueJobNodes() {
                return this.filteredQueues.flatMap(queue => {
                    const queueId = this.queueGraphId(queue);

                    if (this.zoom >= 1.35) {
                        return (queue.jobs ?? [])
                            .slice(0, 8)
                            .map(job => ({
                                id: this.jobInstanceNodeId(queue, job),
                                queueId,
                                queue: queue.name,
                                connection: queue.connection,
                                name: job.name,
                                individual: true,
                                status: job.status,
                                attempts: job.attempts,
                                age_seconds: job.age_seconds,
                                pending: job.status === 'pending' ? 1 : 0,
                                reserved: job.status === 'reserved' ? 1 : 0,
                                completed: job.status === 'completed' ? 1 : 0,
                                failed: job.status === 'failed' ? 1 : 0,
                                latest_error: job.exception,
                            }));
                    }

                    return (queue.job_classes ?? [])
                        .slice(0, 3)
                        .map(jobClass => ({
                            ...jobClass,
                            id: this.jobNodeId(queue, jobClass.name),
                            queueId,
                            queue: queue.name,
                            connection: queue.connection,
                        }));
                });
            },

            jobNodeId(queue, name) {
                return `job-${queue.driver}-${queue.connection}-${queue.name}-${name}`.replace(/[^a-z0-9-]+/gi, '-').toLowerCase();
            },

            jobInstanceNodeId(queue, job) {
                return `job-${queue.driver}-${queue.connection}-${queue.name}-${job.id ?? job.name}`.replace(/[^a-z0-9-]+/gi, '-').toLowerCase();
            },

            jobNodeStatus(job) {
                if (job.individual) return this.jobStatusClass(job.status);
                if (Number(job.failed ?? 0) > 0) return 'critical';
                if (Number(job.pending ?? 0) > 0 || Number(job.reserved ?? 0) > 0) return 'warning';
                return 'healthy';
            },

            jobNodeFlow(job) {
                return Number(job.pending ?? 0) + Number(job.reserved ?? 0) + Number(job.completed ?? 0) + Number(job.failed ?? 0);
            },

            jobNodeSub(job) {
                if (job.individual) {
                    return `${job.status} · attempts ${this.formatNumber(job.attempts ?? 0)} · ${this.formatDuration(job.age_seconds)}`;
                }

                return this.jobCounts(job);
            },

            jobHref(job) {
                if (!job?.id) return null;
                if (job.inspectable === false) return null;
                if (job.status === 'failed') return `${Horizon.basePath}/failed/${job.id}`;
                if (job.status === 'completed') return `${Horizon.basePath}/jobs/completed/${job.id}`;
                return `${Horizon.basePath}/jobs/pending/${job.id}`;
            },

            isRetryingJob(job) {
                return this.retryingJobs.includes(job?.id);
            },

            retryJob(job) {
                if (!job?.id || this.isRetryingJob(job)) return;

                this.retryingJobs = [...this.retryingJobs, job.id];

                return this.$http.post(Horizon.basePath + '/api/jobs/retry/' + job.id)
                    .then(() => this.refreshFlowPeriodically())
                    .finally(() => {
                        this.retryingJobs = this.retryingJobs.filter(id => id !== job.id);
                    });
            },

            openJobModal(job) {
                if (!job || job.status !== 'failed') return;

                this.selectedJob = job;
                this.selectedJobDetails = null;

                if (job.inspectable === false || !job.id) return;

                this.loadingJobDetails = true;
                this.$http.get(Horizon.basePath + '/api/jobs/failed/' + job.id)
                    .then(response => { this.selectedJobDetails = response.data; })
                    .finally(() => { this.loadingJobDetails = false; });
            },

            closeJobModal() {
                this.selectedJob = null;
                this.selectedJobDetails = null;
                this.loadingJobDetails = false;
            },

            modalJobName() {
                return this.selectedJobDetails?.name ?? this.selectedJob?.name ?? 'Queued job';
            },

            modalJobError() {
                return this.selectedJobDetails?.exception ?? this.selectedJob?.exception ?? 'No exception text was captured.';
            },

            edge(source, target, status, label, rate) {
                return { id: `${source}-${target}`, source, target, status, label, rate_per_minute: rate };
            },

            distributedY(index, total, min, max) {
                return total <= 1 ? (min + max) / 2 : min + ((max - min) / (total - 1)) * index;
            },

            findQueueNode(queue) {
                return (this.flow?.nodes ?? []).find(n =>
                    n.type === 'queue' && (n.id === this.queueNodeId(queue) || n.label === queue.name || n.id.endsWith(`-${queue.name}`))
                );
            },

            queueNodeId(queue) {
                return `queue-${queue.driver}-${queue.connection}-${queue.name}`.replace(/[^a-z0-9-]+/gi, '-').toLowerCase();
            },

            // The graph node id a queue actually renders under: the backend's id
            // when the payload carries one, otherwise the local fallback. Job
            // nodes and the inspector must resolve queues through the same id,
            // or their edges never connect to the queue they belong to.
            queueGraphId(queue) {
                return this.findQueueNode(queue)?.id ?? this.queueNodeId(queue);
            },

            queueStatus(queue) {
                if (queue.paused) return 'warning';
                if (this.queueFailedInWindow(queue) > 0) return 'critical';
                if (queue.wait_seconds >= 30 || queue.pending >= 500) return 'critical';
                if (queue.wait_seconds >= 10 || queue.pending >= 100 || queue.delayed > 0) return 'warning';
                return 'healthy';
            },

            queueSubLabel(queue) {
                if (queue.paused) return `${queue.connection} · paused`;
                const failed = this.queueFailedInWindow(queue);
                if (failed > 0) return `${queue.connection} · ${this.formatNumber(failed)} failed`;
                return `${queue.driver} · ${queue.connection} · ${this.formatNumber(queue.pending)} pending`;
            },

            // Prefer the server's windowed count — the repository computes it
            // by filtering the failed_jobs index by failed_at against the
            // ?window= parameter forwarded from the summary call.
            queueFailedInWindow(queue) {
                if (queue?.failed_in_window !== undefined && queue?.failed_in_window !== null) {
                    return Number(queue.failed_in_window);
                }

                const failed = Number(queue?.failed ?? 0);
                if (failed === 0) return 0;

                const lastFailedAt = this.parseTimestamp(queue?.last_failed_at);
                if (lastFailedAt === null) return failed;

                const cutoff = Math.floor(Date.now() / 1000) - this.timeRangeSeconds();
                return lastFailedAt >= cutoff ? failed : 0;
            },

            parseTimestamp(value) {
                if (value === null || value === undefined || value === '') return null;
                if (typeof value === 'number') return value;
                const parsed = Date.parse(String(value));
                return Number.isFinite(parsed) ? Math.floor(parsed / 1000) : null;
            },

            resultSubLabel(node) {
                if (node.label === 'failed') {
                    const nodeWindowed = node.metrics?.failed_in_window;
                    const summaryWindowed = this.summary.failed_in_window;
                    const allTime = node.metrics?.failed ?? this.summary.failed;
                    const windowed = nodeWindowed ?? summaryWindowed;

                    // When the window has no failures but historical ones exist,
                    // show the all-time count so the user isn't left wondering why
                    // a queue with failed jobs reports "0 failed".
                    if ((windowed === 0 || windowed === null || windowed === undefined) && Number(allTime ?? 0) > 0) {
                        return `${this.formatNumber(allTime)} failed (all-time)`;
                    }

                    const value = (windowed !== null && windowed !== undefined) ? windowed : allTime;
                    return `${this.formatNumber(value ?? 0)} failed`;
                }
                if (node.label === 'delayed') return `${this.formatNumber(this.summary.delayed)} delayed`;
                const completedWindowed = this.summary.completed_in_window;
                const completedValue = (completedWindowed !== null && completedWindowed !== undefined)
                    ? completedWindowed
                    : this.summary.completed;
                return `${this.formatNumber(completedValue)} completed`;
            },

            inspectorMetrics(node, queue, jobClass) {
                if (jobClass) return [
                    ['queue',         queue?.name ?? '—'],
                    ['class',         this.shortJobName(jobClass.name)],
                    ['full name',     jobClass.name],
                    ['pending',       this.formatNumber(jobClass.pending ?? 0)],
                    ['reserved',      this.formatNumber(jobClass.reserved ?? 0)],
                    ['completed',     this.formatNumber(jobClass.completed ?? 0)],
                    ['failed',        this.formatNumber(jobClass.failed ?? 0)],
                    ['attempts',      this.formatNumber(jobClass.attempts ?? 0)],
                    ['latest error',  jobClass.latest_error ?? 'none'],
                ];
                if (queue) {
                    const failedInWindow = this.queueFailedInWindow(queue);
                    const allTimeFailed = Number(queue.failed ?? 0);
                    const windowLabel = this.timeRange.replace(/^Last /i, '').toLowerCase();
                    const failedDisplay = failedInWindow === allTimeFailed
                        ? this.formatNumber(allTimeFailed)
                        : `${this.formatNumber(failedInWindow)} in ${windowLabel} · ${this.formatNumber(allTimeFailed)} all-time`;
                    return [
                        ['source',         queue.source ?? queue.driver],
                        ['connection',     queue.connection],
                        ['storage',        queue.storage_connection ?? '—'],
                        ['driver',         queue.driver],
                        ['pending',        this.formatNumber(queue.pending)],
                        ['delayed',        this.formatNumber(queue.delayed)],
                        ['oldest pending', this.formatDuration(queue.oldest_pending_seconds ?? queue.wait_seconds)],
                        ['wait',           this.metricValue(queue.wait_seconds, 's')],
                        ['processes',      this.formatNumber(queue.processes)],
                        ['current rate',   this.formatRate(queue.current_throughput_per_minute)],
                        ['recent activity', this.formatRate(queue.recent_activity_per_minute)],
                        ['last measured',  this.formatRate(queue.throughput_per_minute)],
                        ['drain ETA',      this.formatDuration(queue.estimated_drain_seconds)],
                        ['attempts',       this.formatNumber(queue.attempts ?? 0)],
                        ['failed',         failedDisplay],
                        ['failure rate',   this.formatPercent(queue.failure_rate)],
                        ['latest error',   failedInWindow > 0 ? (queue.latest_error ?? 'none') : 'none in window'],
                    ];
                }
                return Object.entries(node.metrics ?? {}).map(([k, v]) => [k.replace(/_/g, ' '), this.formatNumber(v)]);
            },

            suggestedAction(node, queue, jobClass) {
                if (jobClass) {
                    if (Number(jobClass.failed ?? 0) > 0) {
                        return {
                            type: 'critical',
                            title: 'Immediate Action',
                            text: `${this.shortJobName(jobClass.name)} has ${this.formatNumber(jobClass.failed)} failed instance${jobClass.failed === 1 ? '' : 's'}. Inspect the failure details before retrying.`,
                            link: { to: { name: 'failed-jobs' }, text: 'View failed jobs →' },
                        };
                    }
                    if (Number(jobClass.pending ?? 0) > 0 || Number(jobClass.reserved ?? 0) > 0) {
                        return { type: 'warn', title: 'In Flight', text: `${this.shortJobName(jobClass.name)} has ${this.formatNumber((jobClass.pending ?? 0) + (jobClass.reserved ?? 0))} job${(jobClass.pending ?? 0) + (jobClass.reserved ?? 0) === 1 ? '' : 's'} working through the queue.` };
                    }
                    return { type: 'ok', title: 'Status', text: `${this.shortJobName(jobClass.name)} is idle.` };
                }
                // Result nodes (completed/failed) need their own messaging:
                // they have no queue context, and "backpressure" wording for a
                // failed node is nonsense.
                if (node.type === 'result' && node.label === 'failed') {
                    const inWindow = Number(node.metrics?.failed_in_window ?? this.summary.failed_in_window ?? 0);
                    const allTime = Number(node.metrics?.failed ?? this.summary.failed ?? 0);
                    const link = { to: { name: 'failed-jobs' }, text: 'View failed jobs →' };
                    if (inWindow > 0) {
                        return { type: 'critical', title: 'Immediate Action', text: `${this.formatNumber(inWindow)} job${inWindow === 1 ? '' : 's'} failed in the active window. Inspect the failures.`, link };
                    }
                    if (allTime > 0) {
                        return { type: 'warn', title: 'Heads Up', text: `No recent failures, but ${this.formatNumber(allTime)} historical failure${allTime === 1 ? '' : 's'} on record.`, link };
                    }
                    return { type: 'ok', title: 'Status', text: 'No failed jobs.' };
                }
                if (node.status === 'critical') {
                    const failedInWindow = queue ? this.queueFailedInWindow(queue) : 0;
                    if (queue && failedInWindow > 0) {
                        return { type: 'critical', title: 'Immediate Action', text:
                            `${queue.name} has ${this.formatNumber(failedInWindow)} failed job${failedInWindow === 1 ? '' : 's'} in this window. Inspect the failure details before retrying.`,
                            link: { to: { name: 'failed-jobs' }, text: 'View failed jobs →' } };
                    }
                    return { type: 'critical', title: 'Immediate Action', text: queue
                        ? `Backlog is critical on ${queue.name}. Scale workers or reduce dispatch rate.`
                        : 'Backpressure above normal. Inspect the workload.' };
                }
                if (node.status === 'warning')  return { type: 'warn', title: 'Suggested Action', text: queue ? `${queue.name} is showing backpressure. Consider increasing process capacity.` : 'This node is under pressure. Monitor incoming rates.' };
                return { type: 'ok', title: 'Status', text: 'Node is operating normally. No action required.' };
            },
        },
    }
</script>

<template>
    <div class="lf" :class="{ 'lf-dark': isDark }">
        <FlowToolbar
            :flow="flow"
            :source-class="sourceClass"
            :source-label="sourceLabel"
            :generated-at="generatedAt"
            :refreshing="refreshing"
            :live="live"
            v-model:filterText="filterText"
            v-model:timeRange="timeRange"
            @refresh="refreshFlowPeriodically"
            @toggle-live="toggleLive"
        />

        <div class="lf-toast" :class="'lf-toast-' + controlNotice.type" role="status" aria-live="polite" v-if="controlNotice">
            <span>{{ controlNotice.message }}</span>
            <button type="button" aria-label="Dismiss notification" @click="controlNotice = null">×</button>
        </div>

        <!-- source health -->
        <div class="lf-notice lf-notice-warn" v-if="ready && healthBanner">
            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0;opacity:.8">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            {{ healthBanner }}
        </div>

        <!-- demo notice -->
        <div class="lf-notice lf-notice-warn" v-if="ready && isMock">
            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0;opacity:.8">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            Demo data — configure a Redis or database connection to see live telemetry.
        </div>

        <FlowKpis :metrics="kpiMetrics" @navigate="navigateFromMetric" />

        <!-- loading -->
        <div class="lf-loading" v-if="!ready">
            <svg class="lf-spin" width="16" height="16" viewBox="0 0 20 20" fill="none">
                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5" stroke-opacity="0.25"/>
                <path d="M10 2a8 8 0 0 1 8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            loading live flow…
        </div>

        <!-- operational workspace -->
        <div class="lf-workspace" v-if="ready">
            <div class="lf-tabs" role="tablist" aria-label="Live Flow workspace">
                <button
                    v-for="tab in workspaceTabs"
                    :key="tab.key"
                    :id="'lf-tab-' + tab.key"
                    class="lf-tab"
                    :class="{ 'lf-tab-active': activeWorkspaceTab === tab.key }"
                    type="button"
                    role="tab"
                    :aria-selected="activeWorkspaceTab === tab.key"
                    :aria-controls="'lf-panel-' + tab.key"
                    :tabindex="activeWorkspaceTab === tab.key ? 0 : -1"
                    @click="selectWorkspaceTab(tab.key)"
                    @keydown.left.prevent="moveWorkspaceTab(tab.key, -1)"
                    @keydown.right.prevent="moveWorkspaceTab(tab.key, 1)"
                >
                    <span>{{ tab.label }}</span>
                    <span class="lf-tab-count" v-if="tab.count !== null">{{ formatCount(tab.count) }}</span>
                    <span class="lf-tab-context" v-else>{{ selectedNode ? shortJobName(selectedNode.label) : 'Select node' }}</span>
                </button>
            </div>

            <div
                :id="'lf-panel-' + activeWorkspaceTab"
                class="lf-workspace-panel"
                role="tabpanel"
                :aria-labelledby="'lf-tab-' + activeWorkspaceTab"
            >
                <div class="lf-flow-workspace" v-if="activeWorkspaceTab === 'flow'">
                    <div class="lf-flow-viewbar" aria-label="Flow presentation">
                        <div class="lf-view-switch" role="group" aria-label="Choose graph or queue table">
                            <button
                                v-for="mode in [{ key: 'graph', label: 'Graph' }, { key: 'queues', label: 'Queue table' }]"
                                :key="mode.key"
                                class="lf-view-switch-btn"
                                :class="{ 'lf-view-switch-btn-active': flowMode === mode.key }"
                                type="button"
                                :aria-pressed="flowMode === mode.key"
                                @click="flowMode = mode.key"
                            >{{ mode.label }}</button>
                        </div>
                        <span class="lf-flow-view-help">Select a node in the visualization or Inspector.</span>
                    </div>

                    <div class="lf-flow-layout">
                        <div class="lf-flow-primary">
                            <FlowGraph
                                v-if="flowMode === 'graph'"
                                :nodes="graphNodes"
                                :edges="graphEdges"
                                :flow-counts="flowCounts"
                                :live="live"
                                :svg-height="svgHeight"
                                :selected-id="selectedId"
                                :is-dark="isDark"
                                :monitored-tags="monitoredTags"
                                v-model:zoom="zoom"
                                @select="selectNode"
                            />

                            <FlowQueueTable
                                v-else
                                :queues="filteredQueues"
                                :selected-id="selectedId"
                                :filter-text="filterText"
                                :queue-node-id="queueNodeId"
                                :find-queue-node="findQueueNode"
                                :queue-status="queueStatus"
                                :queue-failed-in-window="queueFailedInWindow"
                                @select="selectNode"
                            />
                        </div>

                        <FlowInspector
                            class="lf-flow-inspector"
                            :inspector="selectedInspector"
                            :graph-node-lookup="graphNodeLookup"
                            :retrying-ids="retryingJobs"
                            :controlling-job-ids="controllingJobs"
                            :queue-controlling="selectedInspector.queue ? isControllingQueue(selectedInspector.queue) : false"
                            :nodes="graphNodes"
                            :selected-id="selectedId"
                            :mode="flowMode"
                            @retry="retryJob"
                            @cancel-job="requestJobCancellation"
                            @pause-queue="requestQueuePause"
                            @resume-queue="queue => controlQueue(queue, 'resume')"
                            @open-failed="openJobModal"
                            @open-activity="selectWorkspaceTab('activity')"
                            @open-graph="flowMode = 'graph'"
                            @select="selectNode"
                        />
                    </div>
                </div>

                <SupervisorControls
                    v-else-if="activeWorkspaceTab === 'controls'"
                    :supervisors="supervisors"
                    :controlling="controllingHorizon"
                    @masters-action="requestMastersAction"
                    @supervisor-action="({ supervisor, action }) => controlSupervisor(supervisor, action)"
                />

                <FlowActivity
                    v-else-if="activeWorkspaceTab === 'activity'"
                    :events="flow?.events ?? []"
                />

                <FlowInsights
                    v-else
                    :comparison="comparisonMetrics"
                    :incidents="incidentTimeline"
                    :monitored-tags="monitoredTags"
                    :time-range="timeRange"
                    @navigate="navigateWorkspace"
                    @notice="({ type, message }) => showControlNotice(type, message)"
                />
            </div>
        </div>

        <div class="lf-confirm-backdrop" v-if="controlConfirmation" @click.self="cancelControlConfirmation">
            <div class="lf-confirm" role="alertdialog" aria-modal="true" aria-labelledby="lf-confirm-title" aria-describedby="lf-confirm-text">
                <div class="lf-confirm-icon" aria-hidden="true">!</div>
                <div>
                    <div class="lf-confirm-title" id="lf-confirm-title">{{ controlConfirmation.title }}</div>
                    <div class="lf-confirm-text" id="lf-confirm-text">{{ controlConfirmation.text }}</div>
                </div>
                <div class="lf-confirm-actions">
                    <button class="lf-control-btn" type="button" @click="cancelControlConfirmation">Cancel</button>
                    <button
                        class="lf-control-btn"
                        :class="controlConfirmation.tone === 'danger' ? 'lf-control-btn-danger' : 'lf-control-btn-warning'"
                        type="button"
                        @click="confirmControlAction"
                    >{{ controlConfirmation.confirmLabel }}</button>
                </div>
            </div>
        </div>

        <FailedJobModal
            :job="selectedJob"
            :details="selectedJobDetails"
            :loading="loadingJobDetails"
            :retrying="selectedJob ? isRetryingJob(selectedJob) : false"
            @close="closeJobModal"
            @retry="retryJob"
        />
    </div>
</template>

<style>
    /* ══ DESIGN TOKENS — light ═════════════════════════════════════════════ */
    .lf {
        --lf-bg:      var(--bs-body-bg, #f8f9fa);
        --lf-panel:   var(--bs-card-bg, #ffffff);
        --lf-border:  var(--bs-border-color, #dee2e6);
        --lf-border2: var(--bs-border-color-translucent, rgba(0,0,0,.175));
        --lf-text:    var(--bs-body-color, #212529);
        --lf-muted:   #6b7076;
        --lf-dim:     #70757b;
        --lf-hover:   var(--bs-tertiary-bg, #f8f9fa);

        --lf-violet:  #7746ec;
        --lf-blue:    #2563eb;
        --lf-cyan:    #0891b2;
        --lf-green:   #059669;
        --lf-amber:   #d97706;
        --lf-red:     #dc2626;

        --lf-canvas-bg:    var(--bs-secondary-bg, #e9ecef);
        --lf-canvas-edge:  rgba(16, 20, 28, 0.10);
        --lf-blue-bg:   rgba(37, 99, 235, .08);   --lf-blue-edge:  rgba(37, 99, 235, .30);
        --lf-green-bg:  rgba(5, 150, 105, .08);   --lf-green-edge: rgba(5, 150, 105, .30);
        --lf-red-bg:    rgba(220, 38, 38, .08);   --lf-red-edge:   rgba(220, 38, 38, .30);
        --lf-canvas-inset: inset 0 9px 14px -11px rgba(16, 20, 28, 0.40),
                           inset 0 -5px 10px -10px rgba(16, 20, 28, 0.22);

        --lf-svg-text:  #111827;
        --lf-svg-muted: #6b7280;

        --lf-node-producer-bg:     rgba(37, 99, 235, 0.07);
        --lf-node-producer-stroke: rgba(37, 99, 235, 0.28);
        --lf-node-queue-bg:        rgba(119, 70, 236, 0.07);
        --lf-node-queue-stroke:    rgba(119, 70, 236, 0.28);
        --lf-node-worker-bg:       rgba(5, 150, 105, 0.07);
        --lf-node-worker-stroke:   rgba(5, 150, 105, 0.28);
        --lf-node-result-bg:       rgba(5, 150, 105, 0.07);
        --lf-node-result-stroke:   rgba(5, 150, 105, 0.28);
        --lf-node-warning-bg:      rgba(217, 119, 6, 0.09);
        --lf-node-warning-stroke:  #d97706;
        --lf-node-critical-bg:     rgba(220, 38, 38, 0.07);
        --lf-node-critical-stroke: #dc2626;

        font-size: 12px;
        padding-bottom: 2rem;
    }

    /* ══ DESIGN TOKENS — dark ══════════════════════════════════════════════ */
    .lf.lf-dark {
        /* Keep Horizon's blue-gray family, with a denser operational surface. */
        --lf-bg:      #111827;
        --lf-panel:   #151c2a;
        --lf-border:  #2b3547;
        --lf-border2: #3a465b;
        --lf-text:    #f3f4f6;
        --lf-muted:   #9ca3af;
        --lf-dim:     #6b7280;
        --lf-hover:   #1b2433;

        --lf-violet:  #a78bfa;
        --lf-blue:    #60a5fa;
        --lf-cyan:    #60a5fa;
        --lf-green:   #10b981;
        --lf-amber:   #f59e0b;
        --lf-red:     #ef4444;

        --lf-canvas-bg:    #070b14;
        --lf-canvas-edge:  #151c2a;
        --lf-blue-bg:      #151c2a; --lf-blue-edge:  rgba(96, 165, 250, .45);
        --lf-green-bg:     #151c2a; --lf-green-edge: rgba(16, 185, 129, .45);
        --lf-red-bg:       #151c2a; --lf-red-edge:   rgba(239, 68, 68, .45);
        --lf-canvas-inset: inset 0 1px 0 rgba(255, 255, 255, 0.025);

        --lf-svg-text:  #f3f4f6;
        --lf-svg-muted: #9ca3af;

        --lf-node-producer-bg:     #151c2a;
        --lf-node-producer-stroke: rgba(96, 165, 250, .55);
        --lf-node-queue-bg:        #151c2a;
        --lf-node-queue-stroke:    rgba(167, 139, 250, .55);
        --lf-node-worker-bg:       #151c2a;
        --lf-node-worker-stroke:   rgba(16, 185, 129, .55);
        --lf-node-result-bg:       #151c2a;
        --lf-node-result-stroke:   rgba(16, 185, 129, .55);
        --lf-node-warning-bg:      #151c2a;
        --lf-node-warning-stroke:  #f59e0b;
        --lf-node-critical-bg:     #151c2a;
        --lf-node-critical-stroke: #ef4444;
    }

    .lf.lf-dark .lf-btn-live,
    .lf.lf-dark .lf-btn-paused,
    .lf.lf-dark .lf-live-tag,
    .lf.lf-dark .lf-live-tag-paused,
    .lf.lf-dark .lf-notice-warn,
    .lf.lf-dark .lf-status-warning,
    .lf.lf-dark .lf-status-critical,
    .lf.lf-dark .lf-action-warn,
    .lf.lf-dark .lf-action-critical,
    .lf.lf-dark .lf-job-fail { background: var(--lf-panel); }
    .lf.lf-dark .lf-status-warning { border: 1px solid rgba(245,158,11,.45); }
    .lf.lf-dark .lf-status-critical { border: 1px solid rgba(239,68,68,.45); }

    /* ── TOOLBAR ─────────────────────────────────────────────────────────── */
    .lf-toolbar {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 0 0 10px;
        flex-wrap: wrap;
    }
    .lf-toolbar-gap { flex: 1; min-width: 0; }

    .lf-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .09em;
        text-transform: uppercase;
    }
    /* Which source the data came from is identity, not health. */
    .lf-chip-mock,
    .lf-chip-auto,
    .lf-chip-redis,
    .lf-chip-db     { background: var(--lf-hover); color: var(--lf-muted); border: 1px solid var(--lf-border); }

    .lf-ts { font-size: 11px; color: var(--lf-dim); }

    .lf-input, .lf-select {
        height: 28px;
        padding: 4px 9px;
        border-radius: 3px;
        border: 1px solid var(--lf-border);
        background: var(--lf-panel);
        color: var(--lf-text);
        font-family: inherit;
        font-size: 11px;
        outline: none;
        transition: border-color .12s;
    }
    .lf-input { width: 155px; }
    .lf-input:focus, .lf-select:focus { border-color: var(--lf-violet); }
    .lf-input::placeholder { color: var(--lf-dim); }

    .lf-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        height: 28px;
        padding: 0 10px;
        border-radius: 3px;
        border: 1px solid var(--lf-border);
        background: var(--lf-panel);
        color: var(--lf-muted);
        font-family: inherit;
        font-size: 11px;
        cursor: pointer;
        letter-spacing: .03em;
        transition: all .12s;
    }
    .lf-btn:hover       { border-color: var(--lf-violet); color: var(--lf-violet); background: rgba(119,70,236,.06); }
    .lf-btn-live        { border-color: var(--lf-green);  color: var(--lf-green);  background: rgba(5,150,105,.07); }
    .lf-btn-paused      { border-color: rgba(217,119,6,.35); color: var(--lf-amber); background: rgba(217,119,6,.07); }
    .lf-btn:disabled    { opacity: .6; cursor: wait; }
    .lf-btn:focus-visible,
    .lf-input:focus-visible,
    .lf-select:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: 2px; }

    .lf-toast {
        position: fixed;
        top: 18px;
        right: 18px;
        z-index: 1100;
        display: flex;
        align-items: center;
        gap: 14px;
        max-width: min(380px, calc(100vw - 36px));
        padding: 10px 12px;
        border: 1px solid var(--lf-border);
        border-left-width: 3px;
        border-radius: 5px;
        background: var(--lf-panel);
        color: var(--lf-text);
        box-shadow: 0 12px 32px rgba(0,0,0,.18);
        font-size: 11.5px;
    }
    .lf-toast-success { border-left-color: var(--lf-green); }
    .lf-toast-error { border-left-color: var(--lf-red); }
    .lf-toast button { margin-left: auto; padding: 0; border: 0; background: transparent; color: var(--lf-muted); font-size: 18px; line-height: 1; cursor: pointer; }

    /* ── NOTICE ──────────────────────────────────────────────────────────── */
    .lf-notice {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-radius: 4px;
        font-size: 11.5px;
        margin-bottom: 10px;
    }
    .lf-notice-warn { background: rgba(217,119,6,.09); border: 1px solid rgba(217,119,6,.22); color: var(--lf-amber); }

    /* ── METRICS STRIP ───────────────────────────────────────────────────── */
    .lf-metrics {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 12px;
    }
    .lf-metric {
        position: relative;
        padding: 11px 12px 10px;
        border: 1px solid var(--lf-border);
        border-radius: 6px;
        background: var(--lf-panel);
        min-width: 0;
        color: inherit;
        text-align: left;
        text-decoration: none;
        font-family: inherit;
    }
    button.lf-metric { cursor: pointer; }
    .lf-metric-action { transition: border-color .12s ease, transform .12s ease, background .12s ease; }
    .lf-metric-action:hover { border-color: var(--lf-border2); background: var(--lf-hover); transform: translateY(-1px); text-decoration: none; }
    .lf-metric-action:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: 2px; }
    .lf-metric::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 3px;
        border-radius: 6px 0 0 6px;
        background: var(--lf-border);
    }
    .lf-metric-primary::before { background: var(--lf-violet); }
    .lf-metric-warn::before    { background: var(--lf-amber); }
    .lf-metric-danger::before  { background: var(--lf-red); }
    .lf-metric-ok::before      { background: var(--lf-green); }
    .lf-metric-head { display: flex; align-items: center; gap: 6px; margin-bottom: 7px; }
    .lf-metric-open { margin-left: auto; color: var(--lf-dim); font-size: 10px; }
    .lf-metric-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        border-radius: 4px;
        color: var(--lf-muted);
        background: var(--lf-hover);
        border: 1px solid var(--lf-border);
        flex: 0 0 auto;
    }
    .lf-metric-icon svg { width: 14px; height: 14px; }
    .lf-metric-primary .lf-metric-icon { color: var(--lf-violet); }
    .lf-metric-warn .lf-metric-icon    { color: var(--lf-amber); }
    .lf-metric-danger .lf-metric-icon  { color: var(--lf-red); }
    .lf-metric-ok .lf-metric-icon      { color: var(--lf-green); }
    .lf-metric-label {
        display: block;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .13em;
        color: var(--lf-dim);
        text-transform: uppercase;
        margin-bottom: 0;
    }
    .lf-metric-body { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
    .lf-metric-value {
        display: block;
        font-size: 21px;
        font-weight: 700;
        line-height: 1;
        font-variant-numeric: tabular-nums;
        color: var(--lf-text);
        font-family: ui-monospace, "Cascadia Code", "SF Mono", Consolas, monospace;
        letter-spacing: -.02em;
    }
    .lf-metric-sub {
        display: block;
        font-size: 9.5px;
        color: var(--lf-dim);
        margin-top: 0;
        text-align: right;
        white-space: nowrap;
    }
    .lf-val-primary { color: var(--lf-violet) !important; }
    .lf-val-warn    { color: var(--lf-amber)  !important; }
    .lf-val-danger  { color: var(--lf-red)    !important; }
    .lf-val-ok      { color: var(--lf-green)  !important; }

    /* ── SPARKLINES ──────────────────────────────────────────────────────── */
    .lf-spark { display: block; }

    .lf-trend {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 3px 0;
    }
    .lf-trend-label {
        font-size: 10.5px;
        color: var(--lf-muted);
        min-width: 62px;
    }
    .lf-trend-spark { flex: 1; height: 18px; min-width: 0; }
    .lf-trend-latest {
        font-size: 10.5px;
        font-weight: 600;
        color: var(--lf-text);
        font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
        font-variant-numeric: tabular-nums;
        min-width: 44px;
        text-align: right;
    }

    /* ── LOADING ─────────────────────────────────────────────────────────── */
    .lf-loading {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        padding: 56px 20px;
        color: var(--lf-muted);
        font-size: 12.5px;
    }

    /* ── WORKSPACE TABS ──────────────────────────────────────────────────── */
    .lf-workspace { min-width: 0; }
    .lf-tabs {
        display: flex;
        align-items: flex-end;
        gap: 2px;
        padding: 0 4px;
        border-bottom: 1px solid var(--lf-border);
        overflow-x: auto;
        scrollbar-width: none;
    }
    .lf-tabs::-webkit-scrollbar { display: none; }
    .lf-tab {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 36px;
        padding: 0 12px;
        border: 0;
        background: transparent;
        color: var(--lf-muted);
        font-family: inherit;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
        cursor: pointer;
    }
    .lf-tab::after {
        content: '';
        position: absolute;
        left: 10px;
        right: 10px;
        bottom: -1px;
        height: 2px;
        border-radius: 2px 2px 0 0;
        background: transparent;
    }
    .lf-tab:hover { color: var(--lf-text); }
    .lf-tab:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: -2px; border-radius: 4px; }
    .lf-tab-active { color: var(--lf-text); }
    .lf-tab-active::after { background: var(--lf-violet); }
    .lf-tab-count,
    .lf-tab-context {
        padding: 1px 5px;
        border-radius: 999px;
        background: var(--lf-hover);
        border: 1px solid var(--lf-border);
        color: var(--lf-dim);
        font-family: ui-monospace, Consolas, monospace;
        font-size: 9px;
        font-weight: 600;
    }
    .lf-tab-context { max-width: 100px; overflow: hidden; text-overflow: ellipsis; }
    .lf-workspace-panel { padding-top: 12px; min-width: 0; }
    .lf-workspace-panel > .lf-pane-gap { margin-top: 0; }

    .lf-flow-viewbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }
    .lf-view-switch {
        display: inline-flex;
        padding: 2px;
        border: 1px solid var(--lf-border);
        border-radius: 5px;
        background: var(--lf-panel);
    }
    .lf-view-switch-btn {
        min-height: 27px;
        padding: 0 11px;
        border: 0;
        border-radius: 3px;
        background: transparent;
        color: var(--lf-muted);
        font-family: inherit;
        font-size: 10.5px;
        font-weight: 650;
        cursor: pointer;
    }
    .lf-view-switch-btn:hover { color: var(--lf-text); }
    .lf-view-switch-btn-active { background: var(--lf-hover); color: var(--lf-text); box-shadow: inset 0 0 0 1px var(--lf-border); }
    .lf-view-switch-btn:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: 1px; }
    .lf-flow-view-help { color: var(--lf-dim); font-size: 10.5px; }
    .lf-flow-layout {
        display: grid;
        grid-template-columns: minmax(0, 2fr) minmax(350px, .9fr);
        align-items: start;
        gap: 12px;
    }
    .lf-flow-primary,
    .lf-flow-inspector { min-width: 0; }
    .lf-flow-inspector .lf-inspector-empty { min-height: 280px; padding: 34px 22px; }
    .lf-flow-inspector .lf-inspector-picker { min-width: 0; width: 100%; order: 3; }
    .lf-flow-inspector .lf-pane-head { flex-wrap: wrap; }
    .lf-flow-inspector .lf-pane-title { margin-right: auto; }

    /* ── PANE (card) ─────────────────────────────────────────────────────── */
    .lf-pane {
        background: var(--lf-panel);
        border: 1px solid var(--lf-border);
        border-radius: 5px;
        overflow: hidden;
    }
    .lf-pane-gap    { margin-top: 12px; }

    .lf-pane-head {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-bottom: 1px solid var(--lf-border);
        background: var(--lf-hover);
    }
    .lf-pane-title {
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: .10em;
        text-transform: uppercase;
        color: var(--lf-muted);
    }
    .lf-pane-meta { font-size: 10px; color: var(--lf-dim); }
    .lf-pane-link { margin-left: auto; color: var(--lf-violet); font-size: 10px; font-weight: 650; text-decoration: none; }
    .lf-pane-link:hover { text-decoration: underline; }

    .lf-graph-tags { display: flex; align-items: center; gap: 4px; min-width: 0; }
    .lf-graph-tag {
        max-width: 130px;
        padding: 2px 6px;
        overflow: hidden;
        border: 1px solid var(--lf-border);
        border-radius: 3px;
        color: var(--lf-muted);
        background: var(--lf-panel);
        font-size: 9px;
        text-decoration: none;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .lf-graph-tag span { color: var(--lf-dim); font-family: ui-monospace, Consolas, monospace; }
    .lf-graph-tag:hover { border-color: var(--lf-violet); color: var(--lf-violet); }

    /* ── VIEWPORT CONTROLS ───────────────────────────────────────────────── */
    .lf-vp { display: flex; align-items: center; gap: 2px; margin-left: auto; margin-right: 6px; }
    .lf-vp-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 3px;
        border: 1px solid var(--lf-border);
        background: var(--lf-panel);
        color: var(--lf-muted);
        cursor: pointer;
        padding: 0;
        transition: all .1s;
    }
    .lf-vp-btn:hover { border-color: var(--lf-violet); color: var(--lf-violet); background: rgba(119,70,236,.06); }
    .lf-vp-pct { font-size: 10px; color: var(--lf-dim); min-width: 34px; text-align: center; font-variant-numeric: tabular-nums; font-family: ui-monospace, Consolas, monospace; }

    .lf-live-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 7px;
        border-radius: 3px;
        font-size: 9.5px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        background: rgba(5,150,105,.10);
        color: var(--lf-green);
        border: 1px solid rgba(5,150,105,.22);
    }
    .lf-live-tag-paused {
        background: rgba(217,119,6,.10);
        color: var(--lf-amber);
        border-color: rgba(217,119,6,.22);
    }

    .lf-tag {
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 9.5px;
        font-weight: 600;
        letter-spacing: .07em;
        text-transform: uppercase;
        background: var(--lf-hover);
        color: var(--lf-dim);
        border: 1px solid var(--lf-border);
        margin-left: auto;
    }

    /* ── SVG CANVAS ──────────────────────────────────────────────────────── */
    /* The canvas is a recessed surface the diagram sits in, not a sheet it sits
       on: a hair darker than the pane, an inset hairline, and a soft shadow
       falling from the top edge. No pattern — depth carries it. */
    .lf-canvas {
        overflow: hidden;
        user-select: none;
        background-color: var(--lf-canvas-bg);
        border-top: 1px solid var(--lf-canvas-edge);
        border-bottom: 1px solid var(--lf-canvas-edge);
        box-shadow: var(--lf-canvas-inset);
    }
    .lf-svg { width: 100%; height: auto; min-height: max(500px, calc(100vh - 340px)); display: block; }
    .lf-svg-mono { font-family: ui-monospace, "Cascadia Code", "SF Mono", Consolas, monospace; }
    .lf-svg-node { cursor: pointer; }
    .lf-svg-node:hover > rect:first-child { filter: brightness(1.08); }

    /* ── FLOW STATS STRIP ────────────────────────────────────────────────── */
    .lf-flowstats {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 14px;
        border-top: 1px solid var(--lf-border);
        background: var(--lf-hover);
        font-family: ui-monospace, Consolas, monospace;
        flex-wrap: wrap;
    }
    .lf-flowstats .lf-fs-heading {
        color: var(--lf-muted);
        text-transform: uppercase;
        letter-spacing: 1.4px;
        font-size: 9px;
        font-weight: 600;
        margin-right: 4px;
    }
    .lf-flowstats .lf-fs-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 5px 11px;
        border: 1px solid var(--lf-border);
        border-radius: 999px;
        background: var(--lf-bg);
        color: var(--lf-text);
        line-height: 1;
        font-size: 11px;
        transition: opacity 0.12s ease, transform 0.12s ease;
    }
    .lf-flowstats .lf-fs-num {
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }
    .lf-flowstats .lf-fs-lbl {
        color: var(--lf-muted);
        text-transform: uppercase;
        letter-spacing: 0.9px;
        font-size: 9px;
        font-weight: 500;
    }
    .lf-flowstats .lf-fs-dispatched { border-color: rgba(96,165,250,0.35); }
    .lf-flowstats .lf-fs-dispatched .lf-fs-num { color: var(--lf-blue); }
    .lf-flowstats .lf-fs-reserved   { border-color: rgba(167,139,250,0.35); }
    .lf-flowstats .lf-fs-reserved   .lf-fs-num { color: var(--lf-violet); }
    .lf-flowstats .lf-fs-completed  { border-color: rgba(74,222,128,0.4); }
    .lf-flowstats .lf-fs-completed  .lf-fs-num { color: var(--lf-green); }
    .lf-flowstats .lf-fs-failed     { border-color: rgba(248,113,113,0.4); }
    .lf-flowstats .lf-fs-failed     .lf-fs-num { color: var(--lf-red); }
    .lf-flowstats .lf-fs-dim { opacity: 0.5; }
    /* ── TABLE ───────────────────────────────────────────────────────────── */
    .lf-queue-summary { display: flex; align-items: center; gap: 5px; margin-left: auto; }
    .lf-queue-summary-item {
        padding: 2px 6px;
        border-radius: 3px;
        border: 1px solid var(--lf-border);
        background: var(--lf-panel);
        color: var(--lf-muted);
        font-size: 9px;
        font-weight: 600;
        white-space: nowrap;
    }
    .lf-queue-summary-warning { color: var(--lf-amber); border-color: rgba(217,119,6,.25); }
    .lf-queue-summary-critical { color: var(--lf-red); border-color: rgba(220,38,38,.25); }
    .lf-tbl-wrap { overflow: auto; max-height: min(620px, calc(100vh - 330px)); }
    .lf-tbl      { width: 100%; border-collapse: collapse; }
    .lf-tbl th {
        padding: 6px 10px;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .11em;
        text-transform: uppercase;
        color: var(--lf-dim);
        border-bottom: 1px solid var(--lf-border);
        background: var(--lf-hover);
        white-space: nowrap;
        text-align: left;
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .lf-tbl td {
        padding: 5px 10px;
        font-size: 11.5px;
        color: var(--lf-text);
        border-bottom: 1px solid var(--lf-border);
        white-space: nowrap;
    }
    .lf-tbl th.sortable { user-select: none; transition: color .1s; }
    .lf-sort-btn {
        display: inline-flex;
        align-items: center;
        justify-content: inherit;
        width: 100%;
        padding: 0;
        border: 0;
        background: transparent;
        color: inherit;
        font: inherit;
        letter-spacing: inherit;
        text-transform: inherit;
        cursor: pointer;
    }
    .lf-tbl th.r .lf-sort-btn { justify-content: flex-end; }
    .lf-sort-btn:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: 2px; border-radius: 2px; }
    .lf-tbl th.sortable:hover { color: var(--lf-violet); }
    .lf-tbl th.lf-th-active { color: var(--lf-violet); }
    .lf-th-arrow { margin-left: 3px; font-size: 7px; }
    .lf-tbl .r { text-align: right; }
    .lf-tbl .num { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: 11px; font-variant-numeric: tabular-nums; }
    .lf-tbl .muted { color: var(--lf-muted); }
    .lf-tbl .warn  { color: var(--lf-amber) !important; }
    .lf-tbl .crit  { color: var(--lf-red)   !important; }
    .lf-tbl .ok    { color: var(--lf-text)   !important; }
    .lf-tbl tbody tr { cursor: pointer; transition: background .08s; }
    .lf-tbl tbody tr:last-child td { border-bottom: none; }
    .lf-tbl tbody tr:hover { background: var(--lf-hover); }
    .lf-tbl tbody tr:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: -2px; }
    .lf-tbl-sel { background: rgba(119,70,236,.05) !important; box-shadow: inset 2px 0 0 var(--lf-violet); }
    .lf-empty { text-align: center !important; padding: 22px 10px !important; color: var(--lf-dim) !important; }

    .lf-qname { display: inline-flex; align-items: center; gap: 6px; }
    .lf-drv { display: inline-block; padding: 1px 5px; border-radius: 2px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
    /* Which driver a queue uses is identity, not health — it gets no colour. */
    .lf-drv-redis,
    .lf-drv-mysql,
    .lf-drv-database,
    .lf-drv-pgsql    { background: var(--lf-hover); color: var(--lf-muted); border: 1px solid var(--lf-border); }

    /* ── STATUS ──────────────────────────────────────────────────────────── */
    /* Status rides on the words that already state it. Healthy is deliberately
       unstyled so only trouble draws the eye. */
    .lf-st-warning  { color: var(--lf-amber); }
    .lf-st-critical { color: var(--lf-red); }

    /* Job lifecycle state. Categorical, not severity — completed and failed
       are both terminal and must never look alike. */
    .lf-jstate {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 1px 6px;
        border-radius: 3px;
        border: 1px solid;
        font-size: 9.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        font-family: ui-monospace, Consolas, monospace;
    }
    .lf-jstate::before { font-size: 11px; line-height: 1; font-weight: 800; }
    .lf-jstate-pending::before   { content: '\25F7'; }
    .lf-jstate-reserved::before  { content: '\25B6'; font-size: 8px; }
    .lf-jstate-cancellation_requested::before { content: '!'; }
    .lf-jstate-cancelled::before { content: '\2212'; }
    .lf-jstate-completed::before { content: '\2713'; }
    .lf-jstate-failed::before    { content: '\00D7'; font-size: 12px; }
    .lf-jstate-pending   { color: var(--lf-muted); border-color: var(--lf-border);        background: var(--lf-hover); }
    .lf-jstate-reserved  { color: var(--lf-blue);  border-color: var(--lf-blue-edge);     background: var(--lf-blue-bg); }
    .lf-jstate-cancellation_requested { color: var(--lf-amber); border-color: rgba(217, 119, 6, .35); background: rgba(217, 119, 6, .08); }
    .lf-jstate-cancelled { color: var(--lf-muted); border-color: var(--lf-border); background: var(--lf-hover); }
    .lf-jstate-completed { color: var(--lf-green); border-color: var(--lf-green-edge);    background: var(--lf-green-bg); }
    .lf-jstate-failed    { color: var(--lf-red);   border-color: var(--lf-red-edge);      background: var(--lf-red-bg); }

    .lf-status { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
    .lf-status-healthy  { background: var(--lf-hover); color: var(--lf-muted); border: 1px solid var(--lf-border); }
    .lf-status-warning  { background: rgba(217,119,6,.10);  color: var(--lf-amber); }
    .lf-status-critical { background: rgba(220,38,38,.10);  color: var(--lf-red); }

    /* ── ACTIVITY ────────────────────────────────────────────────────────── */
    .lf-activity-filters {
        display: flex;
        align-items: center;
        gap: 5px;
        padding: 10px 12px;
        border-bottom: 1px solid var(--lf-border);
        overflow-x: auto;
    }
    .lf-activity-filter {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 28px;
        padding: 0 9px;
        border: 1px solid var(--lf-border);
        border-radius: 4px;
        background: var(--lf-panel);
        color: var(--lf-muted);
        font-family: inherit;
        font-size: 10.5px;
        font-weight: 600;
        white-space: nowrap;
        cursor: pointer;
    }
    .lf-activity-filter:hover { background: var(--lf-hover); color: var(--lf-text); }
    .lf-activity-filter-active { background: var(--lf-hover); color: var(--lf-text); border-color: var(--lf-muted); }
    .lf-activity-filter-pending.lf-activity-filter-active { color: var(--lf-muted); }
    .lf-activity-filter-reserved.lf-activity-filter-active { color: var(--lf-blue); border-color: var(--lf-blue-edge); background: var(--lf-blue-bg); }
    .lf-activity-filter-completed.lf-activity-filter-active { color: var(--lf-green); border-color: var(--lf-green-edge); background: var(--lf-green-bg); }
    .lf-activity-filter-failed.lf-activity-filter-active { color: var(--lf-red); border-color: var(--lf-red-edge); background: var(--lf-red-bg); }
    .lf-activity-filter-count {
        color: inherit;
        font-family: ui-monospace, Consolas, monospace;
        font-size: 9.5px;
        opacity: .8;
    }
    .lf-activity-columns,
    .lf-event {
        display: grid;
        grid-template-columns: 54px minmax(180px, 1fr) minmax(100px, .45fr) 112px;
        gap: 12px;
    }
    .lf-activity-columns {
        padding: 7px 12px;
        border-bottom: 1px solid var(--lf-border);
        background: var(--lf-hover);
        color: var(--lf-dim);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .09em;
        text-transform: uppercase;
    }
    .lf-activity-columns span:last-child { text-align: right; }
    .lf-activity { max-height: min(620px, calc(100vh - 360px)); overflow-y: auto; padding: 3px 0; }
    /* time · job · queue · state — four aligned columns rather than a sentence,
       so a burst of events scans vertically. */
    .lf-event {
        align-items: center;
        padding: 8px 12px;
        border-bottom: 1px solid rgba(127,127,127,.10);
        transition: background .08s;
    }
    .lf-event:hover { background: var(--lf-hover); }
    .lf-event-time {
        font-size: 10px;
        color: var(--lf-dim);
        text-align: right;
        font-family: ui-monospace, Consolas, monospace;
        font-variant-numeric: tabular-nums;
    }
    .lf-event-job   { font-size: 11.5px; color: var(--lf-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lf-event-queue { font-size: 11px; color: var(--lf-dim); white-space: nowrap; }
    .lf-event .lf-jstate { justify-self: end; min-width: 86px; justify-content: center; }
    .lf-event-plain .lf-event-job { grid-column: 2 / -1; }
    .lf-job-sub .lf-jstate { margin-right: 6px; vertical-align: 1px; }
    .lf-job-meta { color: var(--lf-dim); }
    .lf-empty-sm { padding: 6px 12px; font-size: 11px; color: var(--lf-dim); }

    /* ── INSPECTOR ───────────────────────────────────────────────────────── */
    .lf-inspector { align-self: start; }

    .lf-workspace-panel .lf-inspector { width: 100%; }
    .lf-workspace-panel .lf-inspector .lf-action { margin-top: 12px; }

    .lf-inspector-empty {
        grid-column: 1 / -1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 390px;
        padding: 52px 24px;
        text-align: center;
    }
    .lf-inspector-empty svg { width: 52px; height: 52px; color: var(--lf-dim); margin-bottom: 16px; }
    .lf-inspector-empty-title { color: var(--lf-text); font-size: 14px; font-weight: 700; }
    .lf-inspector-empty-text { max-width: 460px; margin: 7px 0 18px; color: var(--lf-muted); font-size: 11.5px; line-height: 1.6; }
    .lf-inspector-picker { margin-left: auto; min-width: min(280px, 45vw); }
    .lf-inspector-picker select {
        width: 100%;
        height: 27px;
        padding: 3px 28px 3px 8px;
        border: 1px solid var(--lf-border);
        border-radius: 4px;
        background: var(--lf-panel);
        color: var(--lf-text);
        font-family: inherit;
        font-size: 10.5px;
    }
    .lf-inspector-picker select:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: 2px; }

    .lf-insp-top { padding: 10px 12px; border-bottom: 1px solid var(--lf-border); }
    .lf-insp-title-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .lf-insp-controls { display: flex; align-items: center; gap: 8px; margin-top: 9px; }
    .lf-insp-control-help { color: var(--lf-dim); font-size: 10px; line-height: 1.35; }
    .lf-insp-name { font-size: 13.5px; font-weight: 600; color: var(--lf-text); margin-bottom: 4px; }
    .lf-insp-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .lf-insp-kind {
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .12em;
        color: var(--lf-muted);
    }
    .lf-insp-conn {
        font-size: 10px;
        padding: 1px 5px;
        border-radius: 2px;
        background: var(--lf-hover);
        border: 1px solid var(--lf-border);
        color: var(--lf-muted);
        font-family: ui-monospace, Consolas, monospace;
    }

    .lf-insp-sec { padding: 8px 12px; border-top: 1px solid var(--lf-border); }
    .lf-insp-sec-title {
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .12em;
        color: var(--lf-dim);
        margin-bottom: 6px;
    }
    .lf-insp-sec-heading { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .lf-insp-sec-actions { display: flex; align-items: center; gap: 8px; }
    .lf-insp-sec-actions > span { color: var(--lf-dim); font: 600 9.5px/1 ui-monospace, Consolas, monospace; }
    .lf-insp-sec-actions button {
        padding: 0;
        border: 0;
        background: transparent;
        color: var(--lf-violet);
        font-family: inherit;
        font-size: 9.5px;
        font-weight: 700;
        cursor: pointer;
    }
    .lf-insp-sec-actions button:hover { text-decoration: underline; }
    .lf-insp-sec-actions button:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: 2px; }
    .lf-insp-connections { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .lf-insp-connections .lf-insp-sec-outgoing { border-left: 1px solid var(--lf-border); }

    .lf-kv {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 8px;
        padding: 2px 0;
    }
    .lf-kv-k {
        font-size: 11px;
        color: var(--lf-muted);
        text-transform: capitalize;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
    }
    .lf-kv-v {
        font-size: 11px;
        font-weight: 500;
        color: var(--lf-text);
        font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
        text-align: right;
        flex-shrink: 0;
    }
    .lf-kv-detail { flex: 1; min-width: 0; color: var(--lf-muted); font-size: 10.5px; text-align: right; }
    .lf-kv-detail summary { color: var(--lf-violet); cursor: pointer; font-size: 10px; font-weight: 650; list-style-position: inside; }
    .lf-kv-detail summary:hover { text-decoration: underline; }
    .lf-kv-detail summary:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: 2px; }
    .lf-kv-detail-body {
        margin-top: 6px;
        padding: 7px 8px;
        border: 1px solid var(--lf-border);
        border-radius: 4px;
        background: var(--lf-bg);
        color: var(--lf-text);
        font: 500 10px/1.5 ui-monospace, "Cascadia Code", Consolas, monospace;
        text-align: left;
        white-space: normal;
    }
    .lf-kv-detail-body strong {
        display: block;
        margin-bottom: 3px;
        overflow: hidden;
        color: var(--lf-text);
        font-weight: 700;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .lf-kv-detail-body span { display: block; overflow-wrap: break-word; color: var(--lf-muted); }

    .lf-job-class,
    .lf-job-row {
        display: flex;
        align-items: flex-start;
        gap: 7px;
        padding: 6px 0;
        border-top: 1px solid rgba(127,127,127,.10);
    }
    .lf-job-class:first-of-type,
    .lf-job-row:first-of-type { border-top: none; padding-top: 0; }
    .lf-job-main { flex: 1; min-width: 0; }
    .lf-job-name {
        color: var(--lf-text);
        font-size: 11.5px;
        font-weight: 600;
        line-height: 1.25;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .lf-job-link { text-decoration: none; }
    .lf-job-link:hover { color: var(--lf-violet); text-decoration: underline; }
    .lf-job-row-clickable { cursor: pointer; }
    .lf-job-row-clickable:hover { background: var(--lf-hover); margin-left: -6px; margin-right: -6px; padding-left: 6px; padding-right: 6px; border-radius: 4px; }
    .lf-job-sub {
        margin-top: 2px;
        color: var(--lf-dim);
        font-size: 10px;
        font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
        line-height: 1.35;
    }
    .lf-job-error {
        margin-top: 4px;
        color: var(--lf-red);
        font-size: 10px;
        line-height: 1.35;
        display: -webkit-box;
        overflow: hidden;
        overflow-wrap: anywhere;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }
    .lf-job-fail {
        padding: 1px 5px;
        border-radius: 3px;
        background: rgba(220,38,38,.09);
        color: var(--lf-red);
        font-size: 9px;
        font-weight: 700;
        white-space: nowrap;
        text-transform: uppercase;
        letter-spacing: .05em;
    }
    .lf-mini-btn {
        border: 1px solid var(--lf-border);
        border-radius: 3px;
        background: var(--lf-hover);
        color: var(--lf-text);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        padding: 3px 6px;
        line-height: 1.1;
    }
    .lf-mini-btn:hover:not(:disabled) { border-color: var(--lf-violet); color: var(--lf-violet); }
    .lf-mini-btn:disabled { opacity: .55; cursor: not-allowed; }
    .lf-job-actions { display: flex; flex: 0 0 auto; flex-direction: column; gap: 4px; }

    .lf-head-actions { display: flex; gap: 6px; margin-left: auto; }
    .lf-control-picker {
        display: flex;
        align-items: center;
        gap: 7px;
        margin-left: auto;
    }
    .lf-control-picker > span { color: var(--lf-dim); font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .lf-control-picker select {
        min-width: 210px;
        height: 28px;
        padding: 3px 28px 3px 8px;
        border: 1px solid var(--lf-border);
        border-radius: 4px;
        background: var(--lf-panel);
        color: var(--lf-text);
        font-family: inherit;
        font-size: 10.5px;
    }
    .lf-control-picker select:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: 2px; }
    .lf-control-btn {
        min-height: 28px;
        padding: 0 10px;
        border: 1px solid var(--lf-border);
        border-radius: 4px;
        background: var(--lf-panel);
        color: var(--lf-text);
        font-family: inherit;
        font-size: 10px;
        font-weight: 650;
        cursor: pointer;
    }
    .lf-control-btn:hover:not(:disabled) { background: var(--lf-hover); }
    .lf-control-btn-warning:hover:not(:disabled) { color: var(--lf-amber); border-color: var(--lf-amber); }
    .lf-control-btn-danger:hover:not(:disabled) { color: var(--lf-red); border-color: var(--lf-red); }
    .lf-control-btn-primary { color: var(--lf-violet); border-color: rgba(119,70,236,.35); }
    .lf-control-btn-primary:hover:not(:disabled) { border-color: var(--lf-violet); }
    .lf-control-btn:focus-visible { outline: 2px solid var(--lf-violet); outline-offset: 2px; }
    .lf-control-btn:disabled { opacity: .42; cursor: not-allowed; }
    .lf-control-overview {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        padding: 12px;
        border-bottom: 1px solid var(--lf-border);
    }
    .lf-control-stat {
        display: flex;
        flex-direction: column;
        gap: 3px;
        padding: 10px 12px;
        border: 1px solid var(--lf-border);
        border-radius: 5px;
        background: var(--lf-hover);
    }
    .lf-control-stat-value { color: var(--lf-text); font: 700 18px/1 ui-monospace, Consolas, monospace; }
    .lf-control-stat-label { color: var(--lf-dim); font-size: 9px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; }
    .lf-control-stat-warning { border-color: rgba(217,119,6,.28); }
    .lf-control-stat-warning .lf-control-stat-value { color: var(--lf-amber); }
    .lf-control-stat-critical { border-color: rgba(220,38,38,.28); }
    .lf-control-stat-critical .lf-control-stat-value { color: var(--lf-red); }
    .lf-supervisors {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        padding: 12px;
    }
    .lf-supervisors-selected { grid-template-columns: minmax(0, 1fr); }
    .lf-supervisor {
        display: flex;
        align-items: stretch;
        flex-direction: column;
        gap: 12px;
        padding: 13px;
        border: 1px solid var(--lf-border);
        border-radius: 6px;
        background: var(--lf-panel);
    }
    .lf-supervisor-main { flex: 1; min-width: 0; }
    .lf-supervisor-title-row { display: flex; align-items: center; gap: 8px; }
    .lf-supervisor-name {
        flex: 1;
        color: var(--lf-text);
        font-size: 12px;
        font-weight: 700;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .lf-supervisor-details { display: grid; grid-template-columns: 1fr 1.5fr .65fr; gap: 8px; margin-top: 12px; }
    .lf-supervisor-detail { min-width: 0; }
    .lf-supervisor-detail span { display: block; color: var(--lf-dim); font-size: 8.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .lf-supervisor-detail strong { display: block; margin-top: 3px; overflow: hidden; color: var(--lf-text); font: 500 10.5px/1.35 ui-monospace, Consolas, monospace; text-overflow: ellipsis; white-space: nowrap; }
    .lf-supervisor-actions { display: flex; justify-content: flex-end; gap: 6px; padding-top: 10px; border-top: 1px solid var(--lf-border); }

    .lf-edge-row {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 2.5px 0;
        font-size: 11px;
    }
    .lf-edge-lbl  { flex: 1; color: var(--lf-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lf-edge-rate { color: var(--lf-dim); font-family: ui-monospace, Consolas, monospace; font-size: 10.5px; white-space: nowrap; }

    .lf-action { margin: 0 12px 12px; padding: 9px 10px; border-radius: 4px; border-left-width: 3px; border-left-style: solid; }
    .lf-action-ok       { background: transparent; border: 1px solid var(--lf-border); border-left: 3px solid var(--lf-border); }
    .lf-action-warn     { background: rgba(217,119,6,.07);  border-color: var(--lf-amber);  border: 1px solid rgba(217,119,6,.18);  border-left: 3px solid var(--lf-amber); }
    .lf-action-critical { background: rgba(220,38,38,.07);  border-color: var(--lf-red);    border: 1px solid rgba(220,38,38,.18);  border-left: 3px solid var(--lf-red); }
    .lf-action-title { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .10em; margin-bottom: 4px; }
    .lf-action-ok       .lf-action-title { color: var(--lf-dim); }
    .lf-action-warn     .lf-action-title { color: var(--lf-amber); }
    .lf-action-critical .lf-action-title { color: var(--lf-red); }
    .lf-action-text { font-size: 11px; color: var(--lf-muted); line-height: 1.55; }
    .lf-action-link {
        display: inline-block;
        margin-top: 6px;
        font-size: 10.5px;
        font-weight: 700;
        text-decoration: none;
    }
    .lf-action-critical .lf-action-link { color: var(--lf-red); }
    .lf-action-warn     .lf-action-link { color: var(--lf-amber); }
    .lf-action-ok       .lf-action-link { color: var(--lf-muted); }
    .lf-action-link:hover { text-decoration: underline; }

    .lf-event-link { text-decoration: none; color: inherit; cursor: pointer; }
    .lf-event-link:hover .lf-event-job { color: var(--lf-text); }

    /* ── INSIGHTS ───────────────────────────────────────────────────────── */
    .lf-insights {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .lf-insight-card { min-width: 0; }
    .lf-compare-list { padding: 2px 0; }
    .lf-compare-row {
        display: grid;
        grid-template-columns: minmax(120px, 1fr) 82px 92px 58px;
        align-items: center;
        gap: 10px;
        padding: 9px 12px;
        border-bottom: 1px solid rgba(127,127,127,.12);
    }
    .lf-compare-row:last-child { border-bottom: 0; }
    .lf-compare-name { color: var(--lf-text); font-size: 11.5px; font-weight: 650; }
    .lf-compare-value { text-align: right; }
    .lf-compare-value span { display: block; color: var(--lf-text); font: 700 12px/1.2 ui-monospace, Consolas, monospace; }
    .lf-compare-value small { display: block; margin-top: 2px; color: var(--lf-dim); font-size: 8.5px; }
    .lf-compare-previous span { color: var(--lf-muted); font-weight: 550; }
    .lf-compare-delta { justify-self: end; padding: 2px 5px; border-radius: 3px; border: 1px solid var(--lf-border); color: var(--lf-muted); font: 650 9px/1.3 ui-monospace, Consolas, monospace; }
    .lf-compare-delta-good { color: var(--lf-green); border-color: var(--lf-green-edge); }
    .lf-compare-delta-bad { color: var(--lf-red); border-color: var(--lf-red-edge); }
    .lf-insight-footer-link { display: block; padding: 8px 12px; border-top: 1px solid var(--lf-border); color: var(--lf-violet); font-size: 10px; font-weight: 650; text-decoration: none; }
    .lf-insight-footer-link:hover { text-decoration: underline; }

    .lf-incident-list { max-height: 300px; overflow-y: auto; }
    .lf-incident {
        display: grid;
        grid-template-columns: 3px minmax(0, 1fr) auto;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 8px 12px;
        border: 0;
        border-bottom: 1px solid rgba(127,127,127,.12);
        background: transparent;
        color: inherit;
        font-family: inherit;
        text-align: left;
        text-decoration: none;
    }
    button.lf-incident,
    a.lf-incident { cursor: pointer; }
    button.lf-incident:hover,
    a.lf-incident:hover { background: var(--lf-hover); text-decoration: none; }
    .lf-incident-mark { width: 3px; height: 28px; border-radius: 2px; background: var(--lf-border); }
    .lf-incident-mark-info { background: var(--lf-blue); }
    .lf-incident-mark-warning { background: var(--lf-amber); }
    .lf-incident-mark-critical { background: var(--lf-red); }
    .lf-incident-main { min-width: 0; }
    .lf-incident-main strong { display: block; overflow: hidden; color: var(--lf-text); font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
    .lf-incident-main small { display: block; margin-top: 2px; overflow: hidden; color: var(--lf-muted); font-size: 9.5px; text-overflow: ellipsis; white-space: nowrap; }
    .lf-incident-time { color: var(--lf-dim); font: 9.5px/1 ui-monospace, Consolas, monospace; }

    .lf-tag-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; padding: 12px; }
    .lf-monitor-tag {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        min-width: 0;
        padding: 9px 10px;
        border: 1px solid var(--lf-border);
        border-radius: 5px;
        background: var(--lf-hover);
        color: var(--lf-text);
        text-decoration: none;
    }
    .lf-monitor-tag:hover { border-color: var(--lf-violet); text-decoration: none; }
    .lf-monitor-tag-name { overflow: hidden; font: 550 10.5px/1.3 ui-monospace, Consolas, monospace; text-overflow: ellipsis; white-space: nowrap; }
    .lf-monitor-tag-count { flex: 0 0 auto; color: var(--lf-muted); font-size: 9px; }

    .lf-batch-list { max-height: 340px; overflow-y: auto; }
    .lf-batch-row {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 8px 12px;
        border-bottom: 1px solid rgba(127,127,127,.12);
    }
    .lf-batch-main { flex: 1; min-width: 0; color: inherit; text-decoration: none; }
    .lf-batch-main strong { display: block; overflow: hidden; color: var(--lf-text); font-size: 10.5px; text-overflow: ellipsis; white-space: nowrap; }
    .lf-batch-main small { display: block; margin-top: 3px; color: var(--lf-muted); font-size: 9px; }
    .lf-batch-progress { display: block; height: 3px; margin-top: 5px; overflow: hidden; border-radius: 2px; background: var(--lf-border); }
    .lf-batch-progress span { display: block; height: 100%; background: var(--lf-violet); }
    .lf-batch-state { flex: 0 0 auto; padding: 2px 5px; border: 1px solid var(--lf-border); border-radius: 3px; color: var(--lf-muted); font-size: 8.5px; font-weight: 700; text-transform: uppercase; }
    .lf-batch-state-info { color: var(--lf-blue); border-color: var(--lf-blue-edge); }
    .lf-batch-state-warning { color: var(--lf-amber); }
    .lf-batch-state-critical { color: var(--lf-red); border-color: var(--lf-red-edge); }
    .lf-insight-empty { padding: 28px 16px; color: var(--lf-muted); font-size: 11px; line-height: 1.5; text-align: center; }
    .lf-insight-empty a { color: var(--lf-violet); }

    /* ── CONTROL CONFIRMATION ───────────────────────────────────────────── */
    .lf-confirm-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1080;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(0,0,0,.48);
    }
    .lf-confirm {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr);
        gap: 12px;
        width: min(460px, 100%);
        padding: 18px;
        border: 1px solid var(--lf-border2);
        border-radius: 7px;
        background: var(--lf-panel);
        box-shadow: 0 22px 60px rgba(0,0,0,.3);
    }
    .lf-confirm-icon { display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border: 1px solid rgba(217,119,6,.4); border-radius: 50%; color: var(--lf-amber); font-weight: 800; }
    .lf-confirm-title { color: var(--lf-text); font-size: 13px; font-weight: 700; }
    .lf-confirm-text { margin-top: 6px; color: var(--lf-muted); font-size: 11px; line-height: 1.55; }
    .lf-confirm-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 7px; margin-top: 5px; }

    /* ── MODAL ───────────────────────────────────────────────────────────── */
    .lf-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1050;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, .48);
        backdrop-filter: blur(3px);
    }
    .lf-modal {
        width: min(760px, 100%);
        max-height: min(720px, calc(100vh - 48px));
        overflow: hidden;
        border: 1px solid var(--lf-border);
        border-radius: 8px;
        background: var(--lf-panel);
        box-shadow: 0 24px 80px rgba(15, 23, 42, .30);
        color: var(--lf-text);
    }
    .lf-modal-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        border-bottom: 1px solid var(--lf-border);
        background: var(--lf-hover);
    }
    .lf-modal-kicker {
        color: var(--lf-red);
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .12em;
        text-transform: uppercase;
    }
    .lf-modal-title { margin-top: 3px; color: var(--lf-text); font-size: 15px; font-weight: 700; }
    .lf-modal-close {
        border: 0;
        background: transparent;
        color: var(--lf-muted);
        font-size: 24px;
        line-height: 1;
        padding: 0 2px;
    }
    .lf-modal-close:hover { color: var(--lf-text); }
    .lf-modal-meta {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        padding: 10px 16px;
        border-bottom: 1px solid var(--lf-border);
        color: var(--lf-muted);
        font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
        font-size: 11px;
    }
    .lf-modal-loading { padding: 16px; color: var(--lf-muted); font-size: 12px; }
    .lf-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding: 12px 16px;
    }

    /* ── DRAG ────────────────────────────────────────────────────────────── */
    .lf-svg-node-dragging { opacity: .92; }
    .lf-svg-node-dragging > rect:first-child { filter: brightness(1.12); }

    /* ── ZOOM MODE BADGE ─────────────────────────────────────────────────── */
    .lf-zoom-mode-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 7px;
        border-radius: 3px;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        border: 1px solid;
        transition: all .2s;
        cursor: default;
    }
    .lf-zmb-class,
    .lf-zmb-individual { background: var(--lf-hover); color: var(--lf-muted); border-color: var(--lf-border); }

    /* ── VIEWPORT RESET BUTTON ───────────────────────────────────────────── */
    .lf-vp-reset { color: var(--lf-amber); }
    .lf-vp-reset:hover { border-color: var(--lf-amber); color: var(--lf-amber); background: rgba(217,119,6,.08); }

    /* ── HORIZON CONTROLS: DANGER / SAFE ────────────────────────────────── */
    .lf-mini-btn-danger:hover:not(:disabled) { border-color: var(--lf-red);   color: var(--lf-red); }
    .lf-mini-btn-safe:hover:not(:disabled)   { border-color: var(--lf-green); color: var(--lf-green); }

    /* ── LEGEND JOB TYPE ─────────────────────────────────────────────────── */

    /* ── MODAL: IMPROVED STACK TRACE ─────────────────────────────────────── */
    .lf-modal-error {
        margin: 0;
        max-height: 380px;
        overflow: auto;
        padding: 14px 16px;
        background: rgba(220,38,38,.04);
        color: var(--lf-text);
        border-bottom: 1px solid var(--lf-border);
        font-family: ui-monospace, "Cascadia Code", "SF Mono", Consolas, monospace;
        font-size: 11px;
        line-height: 1.65;
        white-space: pre-wrap;
        word-break: break-all;
        tab-size: 2;
    }
    .lf-dark .lf-modal-error { background: rgba(220,38,38,.07); }

    /* ── MOBILE ──────────────────────────────────────────────────────────── */
    @media (max-width: 680px) {
        .lf-pane-head { flex-wrap: wrap; row-gap: 5px; }
        .lf-vp { margin-left: 0; margin-top: 2px; width: 100%; justify-content: flex-end; }
        .lf-live-tag { margin-left: auto; }
        .lf-zoom-mode-badge { order: -1; }
        .lf-toolbar { gap: 5px; }
        .lf-input { width: 120px; }
        .lf-modal { border-radius: 6px; }
        .lf-modal-error { font-size: 10.5px; }
        .lf-tabs { padding-left: 0; }
        .lf-tab { padding: 0 9px; }
        .lf-tab-context { display: none; }
        .lf-queue-summary { width: 100%; margin-left: 0; overflow-x: auto; }
        .lf-activity-columns,
        .lf-event { grid-template-columns: 42px minmax(150px, 1fr) 92px; }
        .lf-activity-columns span:nth-child(3),
        .lf-event-queue { display: none; }
        .lf-control-overview { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lf-supervisors { grid-template-columns: 1fr; }
        .lf-supervisor-details { grid-template-columns: 1fr 1fr; }
    }

    /* ── ANIMATIONS ──────────────────────────────────────────────────────── */
    @keyframes lf-spin-anim  { to{transform:rotate(360deg)} }
    .lf-spin { animation: lf-spin-anim .9s linear infinite; display: inline-block; }

    /* ── RESPONSIVE ──────────────────────────────────────────────────────── */
    @media (max-width: 1100px) {
        .lf-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .lf-insights { grid-template-columns: 1fr; }
        .lf-flow-layout { grid-template-columns: 1fr; }
    }
    @media (max-width: 820px) {
        .lf-supervisors { grid-template-columns: 1fr; }
        .lf-workspace-panel .lf-inspector .lf-action { margin-top: 0; }
        .lf-control-picker { width: 100%; order: 4; margin-left: 0; }
        .lf-control-picker select { flex: 1; min-width: 0; }
        .lf-head-actions { margin-left: 0; }
    }
    @media (max-width: 520px) {
        .lf-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lf-metric-body { display: block; }
        .lf-metric-sub { margin-top: 4px; text-align: left; }
        .lf-graph-tags { display: none; }
        .lf-compare-row { grid-template-columns: minmax(0, 1fr) auto; }
        .lf-compare-previous { text-align: left; }
        .lf-tag-grid { grid-template-columns: 1fr; }
        .lf-batch-state { display: none; }
        .lf-flow-viewbar { align-items: flex-start; flex-direction: column; }
        .lf-flow-view-help { display: none; }
    }

    ::-webkit-scrollbar { width: 4px; height: 4px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--lf-border); border-radius: 2px; }
</style>
