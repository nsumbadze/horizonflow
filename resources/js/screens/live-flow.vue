<script type="text/ecmascript-6">
    import FailedJobModal from './live-flow/FailedJobModal.vue';
    import FlowActivity from './live-flow/FlowActivity.vue';
    import FlowGraph from './live-flow/FlowGraph.vue';
    import FlowInspector from './live-flow/FlowInspector.vue';
    import FlowKpis from './live-flow/FlowKpis.vue';
    import FlowQueueTable from './live-flow/FlowQueueTable.vue';
    import FlowToolbar from './live-flow/FlowToolbar.vue';
    import SupervisorControls from './live-flow/SupervisorControls.vue';
    import formatters from './live-flow/formatters';
    import { loadViewState, saveViewState } from './live-flow/viewState';

    const TIME_RANGES = ['Last 5m', 'Last 15m', 'Last 1h', 'Last 6h', 'Last 24h', 'Last 3d', 'Last 7d', 'Last 30d'];

    export default {
        components: { FailedJobModal, FlowActivity, FlowGraph, FlowInspector, FlowKpis, FlowQueueTable, FlowToolbar, SupervisorControls },

        mixins: [formatters],

        data() {
            const saved = loadViewState();

            return {
                flow: null,
                ready: false,
                refreshing: false,
                live: true,
                filterText: typeof saved.filterText === 'string' ? saved.filterText : '',
                timeRange: TIME_RANGES.includes(saved.timeRange) ? saved.timeRange : 'Last 15m',
                selectedId: null,
                isDark: this.sniffDark(),
                retryingJobs: [],
                selectedJob: null,
                selectedJobDetails: null,
                loadingJobDetails: false,
                masters: [],
                controllingHorizon: [],
                lastEventTimestamp: 0,
                queueJobDetails: {},
                eventSpawns: [],
                flowCounts: { dispatched: 0, reserved: 0, completed: 0, failed: 0 },
                zoom: Number.isFinite(saved.zoom) ? Math.min(2.5, Math.max(0.35, saved.zoom)) : 1,
            };
        },

        watch: {
            selectedId(id) {
                const node = this.graphNodeLookup[id];
                if (!node || node.type !== 'queue') return;
                const queue = this.queues.find(q => this.queueNodeId(q) === node.id || this.findQueueNode(q)?.id === node.id);
                if (queue) this.fetchQueueJobs(queue);
            },

            timeRange(value) {
                // Reset the events cursor so the next /events poll fetches the
                // freshly-scoped window. Drop the rendered events too — they were
                // tied to the prior window.
                this.lastEventTimestamp = Math.max(0, Math.floor(Date.now() / 1000) - this.timeRangeSeconds());
                this.mergeFlow({ events: [] });
                this.refreshSummary();
                this.refreshGraph();
                this.refreshQueues();
                saveViewState({ timeRange: value });
            },

            filterText(value) {
                saveViewState({ filterText: value });
            },

            zoom(value) {
                saveViewState({ zoom: value });
            },
        },

        mounted() {
            document.title = "HorizonXBrain - Live Flow";
            this.refreshAll().then(() => { this.ready = true; });
            this.loadSupervisorControls();
            this.startPolling();
            this.isDark = this.sniffDark();
            this.initDarkWatcher();
            // Catch up immediately when the tab becomes visible again. The
            // bootstrap flag is cleared so the backlog of events fetched
            // after a long hidden stretch doesn't burst as stale particles.
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
                    const node = this.findQueueNode(queue);
                    return {
                        id: node?.id ?? this.queueNodeId(queue),
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
                    { key: 'pending',    label: 'PENDING',  value: this.metricValue(this.summary.pending),   sub: this.formatNumber(this.queues.length) + ' queues', cls: 'primary' },
                    { key: 'processing', label: 'PROCS',    value: this.metricValue(this.summary.processing), sub: 'active',   cls: '' },
                    { key: 'delayed',    label: 'DELAYED',  value: this.metricValue(this.summary.delayed),    sub: 'scheduled', cls: (this.summary.delayed ?? 0) > 0 ? 'warn' : '' },
                    { key: 'failed',     label: 'FAILED',   value: this.metricValue(failedValue),             sub: failedSub, cls: (failedValue ?? 0) > 0 ? 'danger' : '' },
                    { key: 'flow',       label: 'FLOW',     value: this.metricValue(this.summary.current_throughput_per_minute ?? this.summary.throughput_per_minute), sub: 'jobs / min', cls: 'ok' },
                    { key: 'wait',       label: 'AVG WAIT', value: this.metricValue(this.summary.average_wait_seconds, 's'), sub: 'latency', cls: '' },
                ];
            },

            selectedNode() {
                return this.graphNodeLookup[this.selectedId] ?? this.graphNodes.find(n => n.type === 'queue') ?? this.graphNodes[0];
            },

            selectedInspector() {
                const node = this.selectedNode;
                if (!node) return { node: { status: 'healthy' }, queue: null, jobClass: null, metrics: [], jobClasses: [], jobs: [], incoming: [], outgoing: [], action: { type: 'ok', title: 'Status', text: 'Loading…' } };

                let queue = null;
                let jobClass = null;

                if (node.type === 'job') {
                    queue = this.queues.find(q => this.queueNodeId(q) === node.queueId) ?? null;
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
                    incoming: this.graphEdges.filter(e => e.target === node.id),
                    outgoing: this.graphEdges.filter(e => e.source === node.id),
                    action: this.suggestedAction(node, queue, jobClass),
                };
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

            startPolling() {
                // document.hidden guard: no point polling a tab nobody is
                // looking at — refreshAll() on visibilitychange catches up.
                this._intervals = [
                    setInterval(() => this.shouldPoll() && this.refreshSummary(),  5000),
                    setInterval(() => this.shouldPoll() && this.refreshGraph(),   10000),
                    setInterval(() => this.shouldPoll() && this.refreshQueues(),  10000),
                    setInterval(() => this.shouldPoll() && this.refreshEvents(),   2000),
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
                if (!this.selectedId || !this.graphNodeLookup[this.selectedId]) {
                    this.selectedId = this.graphNodes.find(n => n.type === 'queue')?.id ?? this.graphNodes[0]?.id;
                }
            },

            refreshSummary() {
                return this.$http.get(Horizon.basePath + '/api/flow/summary', {
                    params: { window: this.timeRangeSeconds() },
                })
                    .then(response => this.mergeFlow({
                        source: response.data.source,
                        sources: response.data.sources,
                        errors: response.data.errors ?? [],
                        health: response.data.health ?? [],
                        meta: response.data.meta ?? {},
                        generated_at: response.data.generated_at,
                        summary: response.data.summary ?? {},
                    }))
                    .catch(() => {});
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
                        if (selected) this.fetchQueueJobs(selected);
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
                    // graph doesn't burst with N stale particles on page load.
                    // Subsequent polls are real-time deltas; each event maps to
                    // one dot traveling its edge plus a flow-count bump.
                    if (this._eventsBootstrapped) {
                        this.dispatchEventSpawns(fresh);
                    }
                    this._eventsBootstrapped = true;
                }).catch(() => {});
            },

            dispatchEventSpawns(events) {
                const ordered = [...events].sort((a, b) =>
                    Number(a.timestamp ?? 0) - Number(b.timestamp ?? 0)
                );
                const additions = ordered.map(event => {
                    this._spawnSequence = (this._spawnSequence ?? 0) + 1;
                    this.bumpFlowCount(event);
                    return { ...event, _spawnId: this._spawnSequence };
                });
                if (additions.length === 0) return;
                const next = [...this.eventSpawns, ...additions];
                this.eventSpawns = next.length > 200 ? next.slice(-200) : next;
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

            isControllingHorizon(key) {
                return this.controllingHorizon.includes(key);
            },

            controlMasters(action) {
                const key = `masters:${action}`;
                if (this.isControllingHorizon(key)) return;

                this.controllingHorizon = [...this.controllingHorizon, key];

                return this.$http.post(`${Horizon.basePath}/api/masters/${action}`)
                    .then(() => Promise.all([this.loadSupervisorControls(), this.refreshFlowPeriodically()]))
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
                    .finally(() => {
                        this.controllingHorizon = this.controllingHorizon.filter(item => item !== key);
                    });
            },

            selectNode(id) { this.selectedId = id; },
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
                    const queueId = this.queueNodeId(queue);

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

            queueStatus(queue) {
                if (this.queueFailedInWindow(queue) > 0) return 'critical';
                if (queue.wait_seconds >= 30 || queue.pending >= 500) return 'critical';
                if (queue.wait_seconds >= 10 || queue.pending >= 100 || queue.delayed > 0) return 'warning';
                return 'healthy';
            },

            queueSubLabel(queue) {
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
                        return { type: 'critical', title: 'Immediate Action', text: jobClass.latest_error
                            ? `${this.shortJobName(jobClass.name)} is failing. Latest error: ${jobClass.latest_error}`
                            : `${this.shortJobName(jobClass.name)} has ${this.formatNumber(jobClass.failed)} failed instance${jobClass.failed === 1 ? '' : 's'}. Inspect the failed jobs.` };
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
                    if (inWindow > 0) {
                        return { type: 'critical', title: 'Immediate Action', text: `${this.formatNumber(inWindow)} job${inWindow === 1 ? '' : 's'} failed in the active window. Inspect the failures.` };
                    }
                    if (allTime > 0) {
                        return { type: 'warn', title: 'Heads Up', text: `No recent failures, but ${this.formatNumber(allTime)} historical failure${allTime === 1 ? '' : 's'} on record.` };
                    }
                    return { type: 'ok', title: 'Status', text: 'No failed jobs.' };
                }
                if (node.status === 'critical') {
                    const failedInWindow = queue ? this.queueFailedInWindow(queue) : 0;
                    if (queue && failedInWindow > 0) {
                        return { type: 'critical', title: 'Immediate Action', text: queue.latest_error
                            ? `${queue.name} has ${this.formatNumber(failedInWindow)} failed jobs in window. Latest error: ${queue.latest_error}`
                            : `${queue.name} has ${this.formatNumber(failedInWindow)} failed jobs in window. Inspect the failures.` };
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

        <FlowKpis :metrics="kpiMetrics" />

        <!-- loading -->
        <div class="lf-loading" v-if="!ready">
            <svg class="lf-spin" width="16" height="16" viewBox="0 0 20 20" fill="none">
                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5" stroke-opacity="0.25"/>
                <path d="M10 2a8 8 0 0 1 8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            loading live flow…
        </div>

        <!-- main layout -->
        <div class="lf-main" v-if="ready">
            <div class="lf-col">

                <FlowGraph
                    :nodes="graphNodes"
                    :edges="graphEdges"
                    :event-spawns="eventSpawns"
                    :flow-counts="flowCounts"
                    :live="live"
                    :svg-height="svgHeight"
                    :selected-id="selectedId"
                    :is-dark="isDark"
                    :summary="summary"
                    v-model:zoom="zoom"
                    @select="selectNode"
                />

                <FlowQueueTable
                    :queues="filteredQueues"
                    :selected-id="selectedId"
                    :filter-text="filterText"
                    :queue-node-id="queueNodeId"
                    :find-queue-node="findQueueNode"
                    :queue-status="queueStatus"
                    :queue-failed-in-window="queueFailedInWindow"
                    @select="selectNode"
                />

                <SupervisorControls
                    :supervisors="supervisors"
                    :controlling="controllingHorizon"
                    @masters-action="controlMasters"
                    @supervisor-action="({ supervisor, action }) => controlSupervisor(supervisor, action)"
                />

                <FlowActivity :events="flow?.events ?? []" />

            </div>

            <FlowInspector
                :inspector="selectedInspector"
                :graph-node-lookup="graphNodeLookup"
                :retrying-ids="retryingJobs"
                @retry="retryJob"
                @open-failed="openJobModal"
            />
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
        --lf-muted:   var(--bs-secondary-color, #6c757d);
        --lf-dim:     rgba(108,117,125,.6);
        --lf-hover:   var(--bs-tertiary-bg, #f8f9fa);

        --lf-violet:  #7746ec;
        --lf-blue:    #2563eb;
        --lf-cyan:    #0891b2;
        --lf-green:   #059669;
        --lf-amber:   #d97706;
        --lf-red:     #dc2626;

        --lf-canvas-bg: var(--bs-secondary-bg, #e9ecef);
        --lf-dot-color: rgba(119, 70, 236, 0.10);
        --lf-grid:      rgba(119, 70, 236, 0.055);
        --lf-stage:     rgba(90, 88, 84, 0.50);

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
        /* layout — explicit so Bootstrap 5.3 vars aren't required */
        --lf-panel:   #1c2030;
        --lf-border:  #252e40;
        --lf-text:    #d0dce8;
        --lf-muted:   #6878a0;
        --lf-dim:     rgba(104, 120, 160, .65);
        --lf-hover:   #141824;

        --lf-violet:  #a78bfa;
        --lf-blue:    #4a8fda;
        --lf-cyan:    #00c8d4;
        --lf-green:   #22c878;
        --lf-amber:   #f0a030;
        --lf-red:     #e84050;

        --lf-canvas-bg: #06090d;
        --lf-dot-color: rgba(0, 200, 212, 0.09);
        --lf-grid:      rgba(0, 200, 212, 0.038);
        --lf-stage:     rgba(104, 120, 160, 0.45);

        --lf-svg-text:  #c8d6e8;
        --lf-svg-muted: #6b7e96;

        --lf-node-producer-bg:     rgba(74, 143, 218, 0.10);
        --lf-node-producer-stroke: rgba(74, 143, 218, 0.30);
        --lf-node-queue-bg:        rgba(167, 139, 250, 0.10);
        --lf-node-queue-stroke:    rgba(167, 139, 250, 0.30);
        --lf-node-worker-bg:       rgba(34, 200, 120, 0.10);
        --lf-node-worker-stroke:   rgba(34, 200, 120, 0.30);
        --lf-node-result-bg:       rgba(34, 200, 120, 0.10);
        --lf-node-result-stroke:   rgba(34, 200, 120, 0.30);
        --lf-node-warning-bg:      rgba(240, 160, 48, 0.10);
        --lf-node-warning-stroke:  #f0a030;
        --lf-node-critical-bg:     rgba(232, 64, 80, 0.10);
        --lf-node-critical-stroke: #e84050;
    }

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
    .lf-chip-mock   { background: rgba(37,99,235,.09);  color: var(--lf-blue);   border: 1px solid rgba(37,99,235,.20); }
    .lf-chip-auto   { background: rgba(119,70,236,.09); color: var(--lf-violet); border: 1px solid rgba(119,70,236,.20); }
    .lf-chip-redis  { background: rgba(220,38,38,.09);  color: var(--lf-red);    border: 1px solid rgba(220,38,38,.20); }
    .lf-chip-db     { background: rgba(5,150,105,.09);  color: var(--lf-green);  border: 1px solid rgba(5,150,105,.20); }

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
        display: flex;
        border: 1px solid var(--lf-border);
        border-radius: 5px;
        overflow: hidden;
        background: var(--lf-panel);
        margin-bottom: 12px;
    }
    .lf-metric {
        flex: 1;
        padding: 11px 14px 10px;
        border-right: 1px solid var(--lf-border);
        min-width: 0;
    }
    .lf-metric:last-child { border-right: none; }
    .lf-metric-label {
        display: block;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .13em;
        color: var(--lf-dim);
        text-transform: uppercase;
        margin-bottom: 5px;
    }
    .lf-metric-value {
        display: block;
        font-size: 22px;
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
        margin-top: 4px;
    }
    .lf-val-primary { color: var(--lf-violet) !important; }
    .lf-val-warn    { color: var(--lf-amber)  !important; }
    .lf-val-danger  { color: var(--lf-red)    !important; }
    .lf-val-ok      { color: var(--lf-green)  !important; }

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

    /* ── MAIN GRID ───────────────────────────────────────────────────────── */
    .lf-main { display: grid; grid-template-columns: minmax(0, 1fr) 308px; gap: 12px; }
    .lf-col  { display: flex; flex-direction: column; min-width: 0; }

    /* ── PANE (card) ─────────────────────────────────────────────────────── */
    .lf-pane {
        background: var(--lf-panel);
        border: 1px solid var(--lf-border);
        border-radius: 5px;
        overflow: hidden;
    }
    .lf-pane-gap    { margin-top: 12px; }
    .lf-pane-sticky { position: sticky; top: 10px; max-height: calc(100vh - 20px); overflow-y: auto; }

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
    .lf-canvas {
        overflow: hidden;
        user-select: none;
        background-color: var(--lf-canvas-bg);
        background-image: radial-gradient(circle, var(--lf-dot-color) 1px, transparent 1px);
        background-size: 22px 22px;
        background-position: 11px 11px;
    }
    .lf-svg { width: 100%; height: auto; min-height: max(500px, calc(100vh - 340px)); display: block; }
    .lf-svg-mono { font-family: ui-monospace, "Cascadia Code", "SF Mono", Consolas, monospace; }
    .lf-svg-node { cursor: pointer; }
    .lf-svg-node:hover > rect:first-child { filter: brightness(1.08); }
    .lf-svg-grid line { stroke: var(--lf-grid); stroke-width: 1; }
    .lf-stage { fill: var(--lf-stage); font-size: 8px; letter-spacing: 1.8px; font-family: ui-monospace, Consolas, monospace; }

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
        padding: 5px 11px 5px 9px;
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
    .lf-flowstats .lf-fs-dot {
        width: 7px; height: 7px; border-radius: 50%;
        box-shadow: 0 0 0 2px rgba(255,255,255,0.04);
    }
    .lf-flowstats .lf-fs-dispatched { border-color: rgba(96,165,250,0.35); }
    .lf-flowstats .lf-fs-dispatched .lf-fs-dot { background: var(--lf-blue); }
    .lf-flowstats .lf-fs-reserved   { border-color: rgba(167,139,250,0.35); }
    .lf-flowstats .lf-fs-reserved   .lf-fs-dot { background: var(--lf-violet); }
    .lf-flowstats .lf-fs-completed  { border-color: rgba(74,222,128,0.4); }
    .lf-flowstats .lf-fs-completed  .lf-fs-dot { background: var(--lf-green); }
    .lf-flowstats .lf-fs-failed     { border-color: rgba(248,113,113,0.4); }
    .lf-flowstats .lf-fs-failed     .lf-fs-dot { background: var(--lf-red); }
    .lf-flowstats .lf-fs-dim { opacity: 0.5; }
    .lf-flowstats .lf-fs-spacer { flex: 1 1 auto; }
    .lf-flowstats .lf-fs-inflight {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--lf-cyan);
        font-weight: 600;
        letter-spacing: 0.6px;
        font-size: 10.5px;
        text-transform: uppercase;
    }
    .lf-flowstats .lf-fs-inflight-dot {
        width: 7px; height: 7px; border-radius: 50%;
        background: var(--lf-cyan);
        animation: lf-fs-pulse 1.2s ease-in-out infinite;
    }
    .lf-flowstats .lf-fs-inflight.lf-fs-quiet { color: var(--lf-muted); font-weight: 500; }
    @keyframes lf-fs-pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%      { opacity: 0.4; transform: scale(0.85); }
    }

    /* ── LEGEND ──────────────────────────────────────────────────────────── */
    .lf-legend {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 7px 12px;
        border-top: 1px solid var(--lf-border);
        background: var(--lf-hover);
        flex-wrap: wrap;
    }
    .lf-leg-item { display: inline-flex; align-items: center; gap: 5px; font-size: 10px; color: var(--lf-muted); }
    .lf-ld  { display: inline-block; width: 7px; height: 7px; border-radius: 50%; }
    .lf-ld-producer { background: var(--lf-blue); opacity: .65; }
    .lf-ld-queue    { background: var(--lf-violet); opacity: .65; }
    .lf-ld-worker   { background: var(--lf-green); opacity: .65; }
    .lf-ld-done     { background: var(--lf-green); }
    .lf-ld-fail     { background: var(--lf-red); }
    .lf-ll  { display: inline-block; width: 16px; height: 2px; border-radius: 1px; }
    .lf-ll-ok   { background: var(--lf-cyan); }
    .lf-ll-warn { background: var(--lf-amber); }
    .lf-ll-crit { background: var(--lf-red); }
    .lf-leg-sep { display: inline-block; width: 1px; height: 11px; background: var(--lf-border); }

    /* ── TABLE ───────────────────────────────────────────────────────────── */
    .lf-tbl-wrap { overflow-x: auto; }
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
    }
    .lf-tbl td {
        padding: 5px 10px;
        font-size: 11.5px;
        color: var(--lf-text);
        border-bottom: 1px solid var(--lf-border);
        white-space: nowrap;
    }
    .lf-tbl .r { text-align: right; }
    .lf-tbl .num { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: 11px; font-variant-numeric: tabular-nums; }
    .lf-tbl .muted { color: var(--lf-muted); }
    .lf-tbl .warn  { color: var(--lf-amber) !important; }
    .lf-tbl .crit  { color: var(--lf-red)   !important; }
    .lf-tbl .ok    { color: var(--lf-green)  !important; }
    .lf-tbl tbody tr { cursor: pointer; transition: background .08s; }
    .lf-tbl tbody tr:last-child td { border-bottom: none; }
    .lf-tbl tbody tr:hover { background: var(--lf-hover); }
    .lf-tbl-sel { background: rgba(119,70,236,.05) !important; box-shadow: inset 2px 0 0 var(--lf-violet); }
    .lf-empty { text-align: center !important; padding: 22px 10px !important; color: var(--lf-dim) !important; }

    .lf-qname { display: inline-flex; align-items: center; gap: 6px; }
    .lf-drv { display: inline-block; padding: 1px 5px; border-radius: 2px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
    .lf-drv-redis    { background: rgba(220,38,38,.09);  color: var(--lf-red); }
    .lf-drv-mysql,
    .lf-drv-database { background: rgba(37,99,235,.09);  color: var(--lf-blue); }
    .lf-drv-pgsql    { background: rgba(5,150,105,.09);  color: var(--lf-green); }

    /* ── STATUS ──────────────────────────────────────────────────────────── */
    .lf-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .lf-dot-healthy  { background: var(--lf-green); }
    .lf-dot-warning  { background: var(--lf-amber); }
    .lf-dot-critical { background: var(--lf-red); animation: lf-blink-anim 1s ease-in-out infinite; }

    .lf-status { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
    .lf-status-healthy  { background: rgba(5,150,105,.10);  color: var(--lf-green); }
    .lf-status-warning  { background: rgba(217,119,6,.10);  color: var(--lf-amber); }
    .lf-status-critical { background: rgba(220,38,38,.10);  color: var(--lf-red); }

    /* ── ACTIVITY ────────────────────────────────────────────────────────── */
    .lf-activity { max-height: 200px; overflow-y: auto; padding: 4px 0; }
    .lf-event {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 5px 12px;
        transition: background .08s;
    }
    .lf-event:hover { background: var(--lf-hover); }
    .lf-event-time {
        font-size: 10px;
        color: var(--lf-dim);
        min-width: 28px;
        font-family: ui-monospace, Consolas, monospace;
        font-variant-numeric: tabular-nums;
    }
    .lf-event-label { font-size: 11.5px; color: var(--lf-text); }
    .lf-empty-sm { padding: 6px 12px; font-size: 11px; color: var(--lf-dim); }

    /* ── INSPECTOR ───────────────────────────────────────────────────────── */
    .lf-inspector { align-self: start; }

    .lf-insp-top { padding: 10px 12px; border-bottom: 1px solid var(--lf-border); }
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

    .lf-head-actions { display: flex; gap: 6px; margin-left: auto; }
    .lf-supervisors { padding: 3px 0; }
    .lf-supervisor {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-top: 1px solid var(--lf-border);
    }
    .lf-supervisor:first-child { border-top: none; }
    .lf-supervisor-main { flex: 1; min-width: 0; }
    .lf-supervisor-name {
        color: var(--lf-text);
        font-size: 11.5px;
        font-weight: 700;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .lf-supervisor-sub {
        margin-top: 2px;
        color: var(--lf-muted);
        font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
        font-size: 10px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

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
    .lf-action-ok       { background: rgba(5,150,105,.07);  border-color: var(--lf-green);  border: 1px solid rgba(5,150,105,.18);  border-left: 3px solid var(--lf-green); }
    .lf-action-warn     { background: rgba(217,119,6,.07);  border-color: var(--lf-amber);  border: 1px solid rgba(217,119,6,.18);  border-left: 3px solid var(--lf-amber); }
    .lf-action-critical { background: rgba(220,38,38,.07);  border-color: var(--lf-red);    border: 1px solid rgba(220,38,38,.18);  border-left: 3px solid var(--lf-red); }
    .lf-action-title { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .10em; margin-bottom: 4px; }
    .lf-action-ok       .lf-action-title { color: var(--lf-green); }
    .lf-action-warn     .lf-action-title { color: var(--lf-amber); }
    .lf-action-critical .lf-action-title { color: var(--lf-red); }
    .lf-action-text { font-size: 11px; color: var(--lf-muted); line-height: 1.55; }

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
    .lf-zmb-class      { background: rgba(119,70,236,.08); color: var(--lf-violet); border-color: rgba(119,70,236,.22); }
    .lf-zmb-individual { background: rgba(8,145,178,.10);  color: var(--lf-cyan);   border-color: rgba(8,145,178,.28); }

    /* ── VIEWPORT RESET BUTTON ───────────────────────────────────────────── */
    .lf-vp-reset { color: var(--lf-amber); }
    .lf-vp-reset:hover { border-color: var(--lf-amber); color: var(--lf-amber); background: rgba(217,119,6,.08); }

    /* ── HORIZON CONTROLS: DANGER / SAFE ────────────────────────────────── */
    .lf-mini-btn-danger { border-color: rgba(220,38,38,.30); color: var(--lf-red); }
    .lf-mini-btn-danger:hover:not(:disabled) { border-color: var(--lf-red); background: rgba(220,38,38,.08); color: var(--lf-red); }
    .lf-mini-btn-safe   { border-color: rgba(5,150,105,.30); color: var(--lf-green); }
    .lf-mini-btn-safe:hover:not(:disabled)   { border-color: var(--lf-green); background: rgba(5,150,105,.08); color: var(--lf-green); }

    /* ── LEGEND JOB TYPE ─────────────────────────────────────────────────── */
    .lf-ld-job { background: var(--lf-cyan); opacity: .65; }

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
        .lf-supervisors { overflow-x: auto; }
        .lf-supervisor { min-width: 320px; }
    }

    /* ── BLINK / PULSE ───────────────────────────────────────────────────── */
    .lf-blink {
        display: inline-block;
        width: 6px; height: 6px;
        border-radius: 50%;
        background: currentColor;
        animation: lf-blink-anim 2s ease-in-out infinite;
    }
    .lf-blink-inline { vertical-align: middle; margin-right: 1px; }
    .lf-blink-green  { background: var(--lf-green); }

    /* ── ANIMATIONS ──────────────────────────────────────────────────────── */
    @keyframes lf-blink-anim { 0%,100%{opacity:1} 50%{opacity:.2} }
    @keyframes lf-spin-anim  { to{transform:rotate(360deg)} }
    .lf-spin { animation: lf-spin-anim .9s linear infinite; display: inline-block; }

    /* ── RESPONSIVE ──────────────────────────────────────────────────────── */
    @media (max-width: 1100px) {
        .lf-main { grid-template-columns: 1fr; }
        .lf-inspector .lf-pane-sticky { position: static; max-height: none; }
    }
    @media (max-width: 820px) {
        .lf-metrics { flex-wrap: wrap; }
        .lf-metric  { flex: 1 1 30%; }
    }
    @media (max-width: 520px) {
        .lf-metric { flex: 1 1 45%; }
    }

    ::-webkit-scrollbar { width: 4px; height: 4px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--lf-border); border-radius: 2px; }
</style>
