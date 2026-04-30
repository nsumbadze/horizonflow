<script type="text/ecmascript-6">
    import FailedJobModal from './live-flow/FailedJobModal.vue';
    import FlowActivity from './live-flow/FlowActivity.vue';
    import FlowGraph from './live-flow/FlowGraph.vue';
    import FlowInspector from './live-flow/FlowInspector.vue';
    import FlowKpis from './live-flow/FlowKpis.vue';
    import FlowQueueTable from './live-flow/FlowQueueTable.vue';
    import FlowToolbar from './live-flow/FlowToolbar.vue';
    import SupervisorControls from './live-flow/SupervisorControls.vue';

    export default {
        components: { FailedJobModal, FlowActivity, FlowGraph, FlowInspector, FlowKpis, FlowQueueTable, FlowToolbar, SupervisorControls },

        data() {
            return {
                flow: null,
                ready: false,
                refreshing: false,
                live: true,
                filterText: '',
                timeRange: 'Last 15m',
                selectedId: null,
                isDark: this.sniffDark(),
                retryingJobs: [],
                selectedJob: null,
                selectedJobDetails: null,
                loadingJobDetails: false,
                masters: [],
                controllingHorizon: [],
                particleEvents: [],
                lastEventTimestamp: 0,
                queueJobDetails: {},
            };
        },

        watch: {
            selectedId(id) {
                const node = this.graphNodeLookup[id];
                if (!node || node.type !== 'queue') return;
                const queue = this.queues.find(q => this.queueNodeId(q) === node.id || this.findQueueNode(q)?.id === node.id);
                if (queue) this.fetchQueueJobs(queue);
            },
        },

        mounted() {
            document.title = "HorizonXBrain - Live Flow";
            this.refreshAll().then(() => { this.ready = true; });
            this.loadSupervisorControls();
            this.startPolling();
            this.isDark = this.sniffDark();
            this.initDarkWatcher();
        },

        beforeUnmount() {
            this.stopPolling();
            this._darkObserver?.disconnect();
            this._mq?.removeEventListener('change', this._mqUpdate);
            if (this._storageHandler) window.removeEventListener('storage', this._storageHandler);
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
                        status: node?.status ?? this.queueStatus(queue),
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
                if (failed && Number(this.summary.failed ?? 0) > 0) generated.push(this.edge(workers[workers.length - 1].id, failed.id, 'critical', 'exception', this.summary.failed));
                return generated;
            },

            particles() {
                if (!this.live) return [];

                return this.particleEvents
                    .flatMap((event, index) => this.jobEventParticle(event, index))
                    .slice(0, 60);
            },

            kpiMetrics() {
                return [
                    { key: 'pending',    label: 'PENDING',  value: this.metricValue(this.summary.pending),   sub: this.formatNumber(this.queues.length) + ' queues', cls: 'primary' },
                    { key: 'processing', label: 'PROCS',    value: this.metricValue(this.summary.processing), sub: 'active',   cls: '' },
                    { key: 'delayed',    label: 'DELAYED',  value: this.metricValue(this.summary.delayed),    sub: 'scheduled', cls: (this.summary.delayed ?? 0) > 0 ? 'warn' : '' },
                    { key: 'failed',     label: 'FAILED',   value: this.metricValue(this.summary.failed),     sub: this.timeRange.toLowerCase(), cls: (this.summary.failed ?? 0) > 0 ? 'danger' : '' },
                    { key: 'flow',       label: 'FLOW',     value: this.metricValue(this.summary.current_throughput_per_minute ?? this.summary.throughput_per_minute), sub: 'jobs / min', cls: 'ok' },
                    { key: 'wait',       label: 'AVG WAIT', value: this.metricValue(this.summary.average_wait_seconds, 's'), sub: 'latency', cls: '' },
                ];
            },

            selectedNode() {
                return this.graphNodeLookup[this.selectedId] ?? this.graphNodes.find(n => n.type === 'queue') ?? this.graphNodes[0];
            },

            selectedInspector() {
                const node = this.selectedNode;
                if (!node) return { node: { status: 'healthy' }, queue: null, metrics: [], incoming: [], outgoing: [], action: { type: 'ok', title: 'Status', text: 'Loading…' } };
                const queue = this.queues.find(q => this.queueNodeId(q) === node.id || this.findQueueNode(q)?.id === node.id);
                return {
                    node, queue,
                    metrics: this.inspectorMetrics(node, queue),
                    jobClasses: this.queueJobClasses(queue),
                    jobs: this.queueJobs(queue),
                    incoming: this.graphEdges.filter(e => e.target === node.id),
                    outgoing: this.graphEdges.filter(e => e.source === node.id),
                    action: this.suggestedAction(node, queue),
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
                    'Last 5m': 300,
                    'Last 15m': 900,
                    'Last 1h': 3600,
                    'Last 6h': 21600,
                    'Last 24h': 86400,
                }[this.timeRange] ?? 900;
            },

            startPolling() {
                this._intervals = [
                    setInterval(() => this.live && this.refreshSummary(),  5000),
                    setInterval(() => this.live && this.refreshGraph(),   10000),
                    setInterval(() => this.live && this.refreshQueues(),  10000),
                    setInterval(() => this.live && this.refreshEvents(),   2000),
                ];
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
                return this.$http.get(Horizon.basePath + '/api/flow/summary')
                    .then(response => this.mergeFlow({
                        source: response.data.source,
                        sources: response.data.sources,
                        errors: response.data.errors ?? [],
                        meta: response.data.meta ?? {},
                        generated_at: response.data.generated_at,
                        summary: response.data.summary ?? {},
                    }))
                    .catch(() => {});
            },

            refreshGraph() {
                return this.$http.get(Horizon.basePath + '/api/flow/graph')
                    .then(response => this.mergeFlow({
                        nodes: response.data.nodes ?? [],
                        edges: response.data.edges ?? [],
                    }))
                    .catch(() => {});
            },

            refreshQueues() {
                return this.$http.get(Horizon.basePath + '/api/flow/queues')
                    .then(response => {
                        this.mergeFlow({ queues: response.data.queues ?? [] });
                        const selected = this.selectedQueue();
                        if (selected) this.fetchQueueJobs(selected);
                    })
                    .catch(() => {});
            },

            refreshEvents() {
                return this.$http.get(Horizon.basePath + '/api/flow/events', {
                    params: this.lastEventTimestamp > 0 ? { since: this.lastEventTimestamp } : {},
                }).then(response => {
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
                    this.ingestParticleEvents(fresh);
                }).catch(() => {});
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

            svgId(value) { return String(value).replace(/[^a-z0-9_-]+/gi, '-'); },

            ingestParticleEvents(events) {
                const now = Date.now() / 1000;

                this.particleEvents = events
                    .filter(event => event.queue && event.result)
                    .filter(event => Number(event.timestamp ?? 0) > 0 && now - Number(event.timestamp) <= 60)
                    .slice(0, 30)
                    .map((event, index) => ({
                        ...event,
                        _renderId: `${event.id ?? event.job ?? event.label ?? 'event'}|${event.timestamp ?? 'time'}|${Date.now()}|${index}`,
                    }));
            },

            metricValue(value, suffix = '') {
                if (value === null || value === undefined) return '—';
                return this.formatNumber(value) + suffix;
            },

            formatNumber(value) {
                if (value === null || value === undefined) return '—';
                if (typeof value === 'number' && !Number.isInteger(value)) return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
                return Number(value).toLocaleString();
            },

            formatRate(value)    { return (value === null || value === undefined) ? '—' : `${this.formatNumber(value)}/m`; },

            formatDuration(value) {
                if (value === null || value === undefined) return '—';
                const s = Number(value);
                if (s < 60) return `${s}s`;
                if (s < 3600) return `${Math.round(s / 60)}m`;
                if (s < 86400) return `${(s / 3600).toFixed(1)}h`;
                return `${(s / 86400).toFixed(1)}d`;
            },

            eventRelativeTime(event) {
                const ts = Number(event?.timestamp ?? 0);
                if (!ts) return '—';

                const delta = Math.max(0, Math.floor(Date.now() / 1000) - ts);
                if (delta < 5) return 'now';
                return this.formatDuration(delta);
            },

            formatPercent(value) {
                if (value === null || value === undefined) return '—';
                return `${this.formatNumber(value)}%`;
            },

            shortJobName(name) {
                const value = String(name ?? 'Queued job');
                return value.split('\\').pop();
            },

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

            jobStatusClass(status) {
                return {
                    failed: 'critical',
                    reserved: 'warning',
                    pending: 'warning',
                    completed: 'healthy',
                }[status] ?? 'healthy';
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

            statusLabel(status) {
                return { healthy: 'healthy', warning: 'warn', critical: 'critical' }[status] ?? status;
            },

            edge(source, target, status, label, rate) {
                return { id: `${source}-${target}`, source, target, status, label, rate_per_minute: rate };
            },

            jobEventParticle(event, index) {
                const edge = this.jobEventEdge(event);

                if (!edge) return [];

                return [{
                    id: `${index}-${this.svgId(event._renderId ?? event._key ?? event.id ?? event.job ?? event.label ?? edge.id)}`,
                    edgeId: this.svgId(edge.id),
                    status: event.status ?? edge.status,
                    delay: `${(index % 12) * 0.14}s`,
                    duration: `${this.particleDuration(event.status ?? edge.status)}s`,
                }];
            },

            jobEventEdge(event) {
                const worker = this.graphNodes.find(n => n.type === 'worker');
                const queue = this.graphNodes.find(n => n.type === 'queue' && n.label === event.queue);
                const job = this.graphNodes.find(n => n.type === 'job' && n.queueId === queue?.id && (
                    n.name === event.job || n.label === this.shortJobName(event.job)
                ));
                const completed = this.graphNodes.find(n => n.type === 'result' && n.label === 'completed');
                const failed = this.graphNodes.find(n => n.type === 'result' && n.label === 'failed');

                const target = event.result === 'completed' && job && completed
                    ? [job.id, completed.id]
                    : event.result === 'completed' && worker && completed
                        ? [worker.id, completed.id]
                        : event.result === 'failed' && job && failed
                            ? [job.id, failed.id]
                            : event.result === 'failed' && worker && failed
                                ? [worker.id, failed.id]
                                : event.result === 'workers' && job && worker
                                    ? [job.id, worker.id]
                                    : event.result === 'workers' && queue && worker
                                        ? [queue.id, worker.id]
                                        : event.result === 'queue' && queue && job
                                            ? [queue.id, job.id]
                                            : event.result === 'queue' && queue
                                                ? ['producer-app', queue.id]
                                                : null;

                if (!target) return null;

                return this.graphEdges.find(edge => edge.source === target[0] && edge.target === target[1])
                    ?? (event.result === 'queue' && queue ? this.graphEdges.find(edge => edge.target === queue.id) : null)
                    ?? null;
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
                if ((queue.failed ?? 0) > 0) return 'critical';
                if (queue.wait_seconds >= 30 || queue.pending >= 500) return 'critical';
                if (queue.wait_seconds >= 10 || queue.pending >= 100 || queue.delayed > 0) return 'warning';
                return 'healthy';
            },

            queueSubLabel(queue) {
                if ((queue.failed ?? 0) > 0) return `${queue.connection} · ${this.formatNumber(queue.failed)} failed`;
                return `${queue.driver} · ${queue.connection} · ${this.formatNumber(queue.pending)} pending`;
            },

            resultSubLabel(node) {
                if (node.label === 'failed')  return `${this.formatNumber(this.summary.failed)} failed`;
                if (node.label === 'delayed') return `${this.formatNumber(this.summary.delayed)} delayed`;
                return `${this.formatNumber(this.summary.completed)} completed`;
            },

            nodeKind(node) {
                return { producer: 'PRODUCER', queue: 'QUEUE', job: 'JOB', worker: 'WORKER', result: node.label?.toUpperCase?.() ?? 'RESULT' }[node.type] ?? node.type?.toUpperCase?.();
            },

            particleDuration(status) {
                return { healthy: 1.7, warning: 2.6, critical: 3.2 }[status] ?? 2;
            },

            inspectorMetrics(node, queue) {
                if (queue) return [
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
                    ['failed',         this.formatNumber(queue.failed ?? 0)],
                    ['failure rate',   this.formatPercent(queue.failure_rate)],
                    ['latest error',   queue.latest_error ?? 'none'],
                ];
                return Object.entries(node.metrics ?? {}).map(([k, v]) => [k.replace(/_/g, ' '), this.formatNumber(v)]);
            },

            suggestedAction(node, queue) {
                if (node.status === 'critical') return { type: 'critical', title: 'Immediate Action', text: queue ? (queue.latest_error ? `${queue.name} has failed jobs. Latest error: ${queue.latest_error}` : `Backlog is critical on ${queue.name}. Scale workers or reduce dispatch rate.`) : 'Failures above normal. Inspect failed job payloads.' };
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
                    :particles="particles"
                    :svg-height="svgHeight"
                    :selected-id="selectedId"
                    :is-dark="isDark"
                    :summary="summary"
                    @select="selectNode"
                />

                <FlowQueueTable
                    :queues="filteredQueues"
                    :selected-id="selectedId"
                    :filter-text="filterText"
                    :queue-node-id="queueNodeId"
                    :find-queue-node="findQueueNode"
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
