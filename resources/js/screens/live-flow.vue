<script type="text/ecmascript-6">
    export default {
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
                // viewport
                panX: 0,
                panY: 0,
                zoom: 1,
                isPanning: false,
            };
        },

        mounted() {
            document.title = "HorizonXBrain - Live Flow";
            this.refreshFlowPeriodically();
            this.isDark = this.sniffDark();
            this.initDarkWatcher();
        },

        beforeUnmount() {
            this._darkObserver?.disconnect();
            this._mq?.removeEventListener('change', this._mqUpdate);
        },

        computed: {
            summary() {
                return this.flow?.summary ?? {};
            },

            meta() {
                return this.flow?.meta ?? {};
            },

            appLabel() {
                return this.meta.app_name ?? this.meta.horizon_name ?? 'Laravel application';
            },

            generatedAt() {
                if (!this.flow?.generated_at) return 'Generated: —';
                return 'Generated: ' + new Date(this.flow.generated_at).toLocaleTimeString();
            },

            sourceClass() {
                return { mock: 'mock', redis: 'redis', database: 'db' }[this.flow?.source] ?? 'mock';
            },

            sourceLabel() {
                return { mock: 'mock · demo', redis: 'redis · live', database: 'db · live' }[this.flow?.source] ?? (this.flow ? this.flow.source : 'loading');
            },

            isMock() {
                return this.flow?.source === 'mock';
            },

            queues() {
                return this.flow?.queues ?? [];
            },

            filteredQueues() {
                const f = this.filterText.trim().toLowerCase();
                if (!f) return this.queues;
                return this.queues.filter(q =>
                    [q.name, q.connection, q.driver].filter(Boolean).some(v => String(v).toLowerCase().includes(f))
                );
            },

            nodeLookup() {
                return (this.flow?.nodes ?? []).reduce((acc, n) => { acc[n.id] = n; return acc; }, {});
            },

            graphNodes() {
                const queues = this.filteredQueues.map((queue, i) => {
                    const node = this.findQueueNode(queue);
                    return {
                        id: node?.id ?? this.queueNodeId(queue),
                        type: 'queue',
                        label: queue.name,
                        sub: this.queueSubLabel(queue),
                        status: node?.status ?? this.queueStatus(queue),
                        x: 250, y: this.distributedY(i, this.filteredQueues.length, 82, 310),
                        width: 128, height: 50,
                        metrics: { pending: queue.pending, delayed: queue.delayed, wait: queue.wait_seconds, processes: queue.processes, throughput: queue.throughput_per_minute },
                    };
                });

                const workers = (this.flow?.nodes ?? [])
                    .filter(n => n.type === 'worker').slice(0, 4)
                    .map((n, i, all) => ({
                        id: n.id, type: 'worker', label: n.label,
                        sub: `${this.formatNumber(n.metrics?.processes ?? this.summary.processing)} processes`,
                        status: n.status,
                        x: 500, y: this.distributedY(i, all.length || 1, 75, 285),
                        width: 128, height: 46, metrics: n.metrics ?? {},
                    }));

                const workerNodes = workers.length ? workers : [{
                    id: 'workers', type: 'worker', label: 'workers',
                    sub: `${this.formatNumber(this.summary.processing)} active`,
                    status: 'healthy', x: 500, y: 175, width: 128, height: 46,
                    metrics: { processes: this.summary.processing },
                }];

                const results = (this.flow?.nodes ?? [])
                    .filter(n => n.type === 'result').slice(0, 4)
                    .map((n, i, all) => ({
                        id: n.id, type: 'result', label: n.label,
                        sub: this.resultSubLabel(n), status: n.status,
                        x: 750, y: this.distributedY(i, all.length || 1, 88, 310),
                        width: 132, height: 50, metrics: n.metrics ?? {},
                    }));

                return [
                    { id: 'producer-app', type: 'producer', label: this.appLabel, sub: `${this.meta.environment ?? 'app'} · ${this.formatNumber(this.summary.throughput_per_minute)} jobs/min`, status: 'healthy', x: 28, y: 105, width: 136, height: 52, metrics: { throughput: this.summary.throughput_per_minute } },
                    { id: 'producer-scheduler', type: 'producer', label: 'scheduler', sub: `${this.formatNumber(this.summary.delayed)} delayed`, status: this.summary.delayed > 0 ? 'warning' : 'healthy', x: 28, y: 235, width: 136, height: 52, metrics: { delayed: this.summary.delayed } },
                    ...queues, ...workerNodes, ...results,
                ];
            },

            graphNodeLookup() {
                return this.graphNodes.reduce((acc, n) => { acc[n.id] = n; return acc; }, {});
            },

            graphEdges() {
                const existing = (this.flow?.edges ?? [])
                    .filter(e => this.graphNodeLookup[e.source] && this.graphNodeLookup[e.target]);
                if (existing.length) return existing;

                const workers = this.graphNodes.filter(n => n.type === 'worker');
                const results = this.graphNodes.filter(n => n.type === 'result');
                const completed = results.find(n => n.label === 'completed') ?? results[0];
                const failed = results.find(n => n.label === 'failed');
                const generated = [];

                this.graphNodes.filter(n => n.type === 'queue').forEach((q, i) => {
                    const w = workers[i % workers.length];
                    const producer = (q.status === 'critical' || q.status === 'warning') ? 'producer-scheduler' : 'producer-app';
                    generated.push(this.edge(producer, q.id, q.status, 'dispatch', q.metrics.throughput));
                    generated.push(this.edge(q.id, w.id, q.status, 'reserve', q.metrics.throughput));
                });
                if (completed) workers.forEach(w => generated.push(this.edge(w.id, completed.id, 'healthy', 'finish', this.summary.throughput_per_minute)));
                if (failed && Number(this.summary.failed ?? 0) > 0) generated.push(this.edge(workers[workers.length - 1].id, failed.id, 'critical', 'exception', this.summary.failed));
                return generated;
            },

            particles() {
                if (!this.live) return [];

                return this.graphEdges.filter(edge => Number(edge.rate_per_minute ?? 0) > 0).flatMap((edge, ei) => {
                    const count = edge.status === 'critical' || edge.status === 'warning' ? 2 : Math.min(3, Math.max(1, Math.ceil((edge.rate_per_minute ?? 20) / 120)));
                    return Array.from({ length: count }).map((_, i) => ({
                        id: `${edge.id}-${i}`,
                        edgeId: this.svgId(edge.id),
                        status: edge.status,
                        delay: `${(i / count) * this.particleDuration(edge.status) + (ei % 3) * 0.15}s`,
                        duration: `${this.particleDuration(edge.status)}s`,
                    }));
                }).slice(0, 28);
            },

            viewportTransform() {
                return `translate(${this.panX} ${this.panY}) scale(${this.zoom})`;
            },

            zoomLabel() {
                return Math.round(this.zoom * 100) + '%';
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
                    incoming: this.graphEdges.filter(e => e.target === node.id),
                    outgoing: this.graphEdges.filter(e => e.source === node.id),
                    action: this.suggestedAction(node, queue),
                };
            },
        },

        methods: {
            // ── theme ──────────────────────────────────────────────────────────
            sniffDark() {
                try {
                    const el = document.querySelector('style[data-scheme="dark"]');
                    if (el) return el.media === '' || el.media === 'all';
                    return window.matchMedia('(prefers-color-scheme: dark)').matches;
                } catch { return false; }
            },

            initDarkWatcher() {
                const el = document.querySelector('style[data-scheme="dark"]');
                if (el) {
                    this._darkObserver = new MutationObserver(() => { this.isDark = this.sniffDark(); });
                    this._darkObserver.observe(el, { attributes: true, attributeFilter: ['media'] });
                }
                this._mq = window.matchMedia('(prefers-color-scheme: dark)');
                this._mqUpdate = () => { this.isDark = this.sniffDark(); };
                this._mq.addEventListener('change', this._mqUpdate);
            },

            // ── viewport ───────────────────────────────────────────────────────
            getSVGCoords(e) {
                const svg = this.$refs.flowSvg;
                if (!svg) return { x: e.clientX, y: e.clientY };
                const pt = svg.createSVGPoint();
                pt.x = e.clientX;
                pt.y = e.clientY;
                return pt.matrixTransform(svg.getScreenCTM().inverse());
            },

            onCanvasPointerDown(e) {
                this.isPanning = true;
                this._lastVp = this.getSVGCoords(e);
                e.currentTarget.setPointerCapture(e.pointerId);
            },

            onCanvasPointerMove(e) {
                if (!this.isPanning || !this._lastVp) return;
                const curr = this.getSVGCoords(e);
                this.panX += curr.x - this._lastVp.x;
                this.panY += curr.y - this._lastVp.y;
                this._lastVp = curr;
            },

            onCanvasPointerUp(e) {
                this.isPanning = false;
                this._lastVp = null;
                try { e.currentTarget.releasePointerCapture(e.pointerId); } catch {}
            },

            onCanvasWheel(e) {
                e.preventDefault();
                const pt = this.getSVGCoords(e);
                const factor = e.deltaY < 0 ? 1.12 : 1 / 1.12;
                const newZoom = Math.min(2.5, Math.max(0.35, this.zoom * factor));
                const scale = newZoom / this.zoom;
                this.panX = pt.x - scale * (pt.x - this.panX);
                this.panY = pt.y - scale * (pt.y - this.panY);
                this.zoom = +newZoom.toFixed(4);
            },

            zoomIn()    { this.zoom = Math.min(2.5, +(this.zoom * 1.2).toFixed(4)); },
            zoomOut()   { this.zoom = Math.max(0.35, +(this.zoom / 1.2).toFixed(4)); },
            resetView() { this.panX = 0; this.panY = 0; this.zoom = 1; },

            // ── data ───────────────────────────────────────────────────────────
            refreshFlowPeriodically() {
                if (!this.live && this.ready) return Promise.resolve();
                this.refreshing = true;
                return this.$http.get(Horizon.basePath + '/api/flow')
                    .then(response => {
                        this.flow = response.data;
                        this.ready = true;
                        if (!this.selectedId || !this.graphNodeLookup[this.selectedId]) {
                            this.selectedId = this.graphNodes.find(n => n.type === 'queue')?.id ?? this.graphNodes[0]?.id;
                        }
                    })
                    .finally(() => { this.refreshing = false; });
            },

            selectNode(id) { this.selectedId = id; },
            toggleLive() { this.live = !this.live; },

            svgId(value) {
                return String(value).replace(/[^a-z0-9_-]+/gi, '-');
            },

            // ── formatting ─────────────────────────────────────────────────────
            metricValue(value, suffix = '') {
                if (value === null || value === undefined) return 'n/a';
                return this.formatNumber(value) + suffix;
            },

            formatNumber(value) {
                if (value === null || value === undefined) return 'n/a';
                if (typeof value === 'number' && !Number.isInteger(value)) return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
                return Number(value).toLocaleString();
            },

            formatRate(value) {
                return (value === null || value === undefined) ? 'n/a' : `${this.formatNumber(value)}/m`;
            },

            statusLabel(status) {
                return { healthy: 'healthy', warning: 'backpressure', critical: 'critical' }[status] ?? status;
            },

            // ── graph helpers ──────────────────────────────────────────────────
            edge(source, target, status, label, rate) {
                return { id: `${source}-${target}`, source, target, status, label, rate_per_minute: rate };
            },

            edgePath(edge) {
                const s = this.graphNodeLookup[edge.source];
                const t = this.graphNodeLookup[edge.target];
                if (!s || !t) return '';
                const sx = s.x + s.width, sy = s.y + s.height / 2;
                const tx = t.x, ty = t.y + t.height / 2;
                const bend = Math.max(45, Math.abs(tx - sx) * 0.45);
                return `M ${sx} ${sy} C ${sx + bend} ${sy} ${tx - bend} ${ty} ${tx} ${ty}`;
            },

            edgeLabelPos(edge) {
                const s = this.graphNodeLookup[edge.source];
                const t = this.graphNodeLookup[edge.target];
                return { x: s && t ? (s.x + s.width + t.x) / 2 : 0, y: s && t ? (s.y + t.y) / 2 + 10 : 0 };
            },

            distributedY(index, total, min, max) {
                return total <= 1 ? (min + max) / 2 : min + ((max - min) / (total - 1)) * index;
            },

            findQueueNode(queue) {
                return (this.flow?.nodes ?? []).find(n =>
                    n.type === 'queue' && (n.label === queue.name || n.id === this.queueNodeId(queue) || n.id.endsWith(`-${queue.name}`))
                );
            },

            queueNodeId(queue) {
                return `queue-${queue.connection}-${queue.name}`.replace(/[^a-z0-9-]+/gi, '-').toLowerCase();
            },

            queueStatus(queue) {
                if ((queue.failed ?? 0) > 0) return 'critical';
                if (queue.wait_seconds >= 30 || queue.pending >= 500) return 'critical';
                if (queue.wait_seconds >= 10 || queue.pending >= 100 || queue.delayed > 0) return 'warning';
                return 'healthy';
            },

            queueSubLabel(queue) {
                if ((queue.failed ?? 0) > 0) return `${queue.connection} · ${this.formatNumber(queue.failed)} failed`;
                return `${queue.connection} · ${this.formatNumber(queue.pending)} pending`;
            },

            resultSubLabel(node) {
                if (node.label === 'failed')  return `${this.formatNumber(this.summary.failed)} failed`;
                if (node.label === 'delayed') return `${this.formatNumber(this.summary.delayed)} delayed`;
                return `${this.formatNumber(this.summary.completed)} completed`;
            },

            // ── color methods (CSS variable references) ────────────────────────
            nodeFill(node) {
                if (node.status === 'critical') return 'var(--hxb-node-critical-bg)';
                if (node.status === 'warning')  return 'var(--hxb-node-warning-bg)';
                return { producer: 'var(--hxb-node-producer-bg)', queue: 'var(--hxb-node-queue-bg)', worker: 'var(--hxb-node-worker-bg)', result: 'var(--hxb-node-result-bg)' }[node.type] ?? 'var(--hxb-node-queue-bg)';
            },

            nodeStroke(node) {
                if (node.id === this.selectedId) return 'var(--hxb-violet)';
                if (node.status === 'critical')  return 'var(--hxb-node-critical-stroke)';
                if (node.status === 'warning')   return 'var(--hxb-node-warning-stroke)';
                return { producer: 'var(--hxb-node-producer-stroke)', queue: 'var(--hxb-node-queue-stroke)', worker: 'var(--hxb-node-worker-stroke)', result: 'var(--hxb-node-result-stroke)' }[node.type] ?? 'var(--hxb-node-queue-stroke)';
            },

            nodeStrokeWidth(node) {
                return node.id === this.selectedId ? 2.2 : 1.5;
            },

            nodeAccent(node) {
                if (node.status === 'critical') return 'var(--hxb-red)';
                if (node.status === 'warning')  return 'var(--hxb-amber)';
                return { producer: 'var(--hxb-blue)', queue: 'var(--hxb-cyan)', worker: 'var(--hxb-green)', result: 'var(--hxb-green)' }[node.type] ?? 'var(--hxb-cyan)';
            },

            nodeTextColor(node) {
                if (node.status === 'critical') return 'var(--hxb-red)';
                if (node.status === 'warning')  return 'var(--hxb-amber)';
                return 'var(--hxb-muted)';
            },

            nodeKind(node) {
                return { producer: 'PRODUCER', queue: 'QUEUE', worker: 'WORKER', result: node.label?.toUpperCase?.() ?? 'RESULT' }[node.type] ?? node.type?.toUpperCase?.();
            },

            edgeColor(status) {
                return { healthy: 'var(--hxb-cyan)', warning: 'var(--hxb-amber)', critical: 'var(--hxb-red)' }[status] ?? 'var(--hxb-cyan)';
            },

            particleFilter(status) {
                if (!this.isDark) return 'none';
                return { healthy: 'url(#hxb-f-cyan)', warning: 'url(#hxb-f-amber)', critical: 'url(#hxb-f-red)' }[status] ?? 'url(#hxb-f-cyan)';
            },

            particleDuration(status) {
                return { healthy: 1.7, warning: 2.6, critical: 3.2 }[status] ?? 2;
            },

            edgeDisplayLabel(edge) {
                const rate = Number(edge.rate_per_minute ?? 0);

                if (rate <= 0) {
                    return ['dispatch', 'reserve', 'finish'].includes(edge.label) ? 'idle' : edge.label;
                }

                return this.formatRate(rate);
            },

            // ── inspector ──────────────────────────────────────────────────────
            inspectorMetrics(node, queue) {
                if (queue) return [
                    ['Connection', queue.connection], ['Driver', queue.driver],
                    ['Pending', this.formatNumber(queue.pending)], ['Delayed', this.formatNumber(queue.delayed)],
                    ['Wait', this.metricValue(queue.wait_seconds, 's')], ['Processes', this.formatNumber(queue.processes)],
                    ['Throughput', this.formatRate(queue.throughput_per_minute)],
                    ['Failed', this.formatNumber(queue.failed ?? 0)], ['Latest error', queue.latest_error ?? 'none'],
                ];
                return Object.entries(node.metrics ?? {}).map(([k, v]) => [k.replace(/_/g, ' '), this.formatNumber(v)]);
            },

            suggestedAction(node, queue) {
                if (node.status === 'critical') return { type: 'critical', title: 'Immediate Action', text: queue ? (queue.latest_error ? `${queue.name} has failed jobs. Latest error: ${queue.latest_error}` : `Backlog is critical on ${queue.name}. Scale workers or reduce dispatch rate. Example: php artisan horizon:supervisor ${queue.name}`) : 'Failures above normal. Inspect failed job payloads and retry only after root cause is fixed.' };
                if (node.status === 'warning')  return { type: 'warn',     title: 'Suggested Action', text: queue ? `${queue.name} is showing backpressure. Watch wait time and consider increasing process capacity.` : 'This node is under pressure. Monitor incoming rates and downstream failures.' };
                return { type: 'ok', title: 'Status', text: 'Node is operating normally. No action required.' };
            },
        },
    }
</script>

<template>
    <div :class="['hxb-live-flow', { 'hxb-dark': isDark }]">
        <poll @poll="refreshFlowPeriodically" :interval="5" />

        <!-- PAGE CONTROLS -->
        <div class="hxb-page-controls">
            <span class="hxb-source-badge" :class="'hxb-source-' + sourceClass">
                <span class="hxb-pulse"></span>
                {{ sourceLabel }}
            </span>
            <span class="hxb-ts">{{ generatedAt }}</span>
            <div class="hxb-spacer"></div>
            <input v-model="filterText" class="hxb-ctl" type="text" placeholder="Filter queues…" />
            <select v-model="timeRange" class="hxb-ctl">
                <option>Last 5m</option><option>Last 15m</option>
                <option>Last 1h</option><option>Last 6h</option><option>Last 24h</option>
            </select>
            <button class="hxb-btn" type="button" @click="refreshFlowPeriodically">
                <svg :class="{ 'hxb-spinning': refreshing }" width="12" height="12" viewBox="0 0 12 12" fill="none">
                    <path d="M10.5 2A5 5 0 1 0 11 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="M10.5 2V5H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Refresh
            </button>
            <button class="hxb-btn" :class="{ active: live }" type="button" @click="toggleLive">
                <span class="hxb-pulse hxb-pulse-green"></span>
                Live
            </button>
        </div>

        <!-- DEMO NOTICE -->
        <div class="hxb-demo-notice" v-if="ready && isMock">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            Demo data — not connected to a real queue. Configure a Redis or database connection to see live telemetry.
        </div>

        <!-- KPI STRIP -->
        <div class="hxb-kpi-strip">
            <div class="hxb-kpi pending">
                <div class="hxb-kpi-label">Pending</div>
                <div class="hxb-kpi-value">{{ metricValue(summary.pending) }}</div>
                <div class="hxb-kpi-sub">across {{ formatNumber(queues.length) }} queues</div>
            </div>
            <div class="hxb-kpi processing">
                <div class="hxb-kpi-label">Processing</div>
                <div class="hxb-kpi-value">{{ metricValue(summary.processing) }}</div>
                <div class="hxb-kpi-sub">active workers</div>
            </div>
            <div class="hxb-kpi delayed">
                <div class="hxb-kpi-label">Delayed</div>
                <div class="hxb-kpi-value">{{ metricValue(summary.delayed) }}</div>
                <div class="hxb-kpi-sub">scheduled</div>
            </div>
            <div class="hxb-kpi failed">
                <div class="hxb-kpi-label">Failed</div>
                <div class="hxb-kpi-value">{{ metricValue(summary.failed) }}</div>
                <div class="hxb-kpi-sub">{{ timeRange.toLowerCase() }}</div>
            </div>
            <div class="hxb-kpi throughput">
                <div class="hxb-kpi-label">Throughput</div>
                <div class="hxb-kpi-value">{{ metricValue(summary.throughput_per_minute) }}</div>
                <div class="hxb-kpi-sub">jobs / min</div>
            </div>
            <div class="hxb-kpi wait">
                <div class="hxb-kpi-label">Avg Wait</div>
                <div class="hxb-kpi-value">{{ metricValue(summary.average_wait_seconds, 's') }}</div>
                <div class="hxb-kpi-sub">queue latency</div>
            </div>
        </div>

        <!-- LOADING -->
        <div class="hxb-loading" v-if="!ready">
            <svg class="hxb-loading-spin" width="18" height="18" viewBox="0 0 20 20" fill="none">
                <circle cx="10" cy="10" r="8" stroke="var(--hxb-border-bright)" stroke-width="2"/>
                <path d="M10 2a8 8 0 0 1 8 8" stroke="var(--hxb-cyan)" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Loading live flow…
        </div>

        <!-- MAIN LAYOUT -->
        <div class="hxb-main" v-if="ready">
            <div class="hxb-left">

                <!-- FLOW GRAPH -->
                <section class="hxb-panel">
                    <div class="hxb-panel-head">
                        <span class="hxb-panel-title">Flow Graph</span>
                        <span class="hxb-panel-sub">{{ graphNodes.length }} nodes · {{ graphEdges.length }} edges</span>
                        <!-- Viewport controls -->
                        <div class="hxb-vp-ctrls">
                            <button class="hxb-vp-btn" @click="zoomOut" title="Zoom out">
                                <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><line x1="2" y1="5.5" x2="9" y2="5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </button>
                            <span class="hxb-vp-zoom">{{ zoomLabel }}</span>
                            <button class="hxb-vp-btn" @click="zoomIn" title="Zoom in">
                                <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><line x1="5.5" y1="2" x2="5.5" y2="9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="2" y1="5.5" x2="9" y2="5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </button>
                            <button class="hxb-vp-btn" @click="resetView" title="Reset / fit view">
                                <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><rect x="1.5" y="1.5" width="3" height="3" rx="0.5" stroke="currentColor" stroke-width="1.3"/><rect x="6.5" y="1.5" width="3" height="3" rx="0.5" stroke="currentColor" stroke-width="1.3"/><rect x="1.5" y="6.5" width="3" height="3" rx="0.5" stroke="currentColor" stroke-width="1.3"/><rect x="6.5" y="6.5" width="3" height="3" rx="0.5" stroke="currentColor" stroke-width="1.3"/></svg>
                            </button>
                        </div>
                        <span class="hxb-panel-badge">live</span>
                    </div>

                    <div class="hxb-canvas-wrap">
                        <svg
                            ref="flowSvg"
                            class="hxb-flow-svg"
                            viewBox="0 0 980 390"
                            xmlns="http://www.w3.org/2000/svg"
                            :style="{ cursor: isPanning ? 'grabbing' : 'grab' }"
                            @pointerdown="onCanvasPointerDown"
                            @pointermove="onCanvasPointerMove"
                            @pointerup="onCanvasPointerUp"
                            @pointerleave="onCanvasPointerUp"
                            @wheel.prevent="onCanvasWheel"
                        >
                            <defs>
                                <filter id="hxb-f-cyan" x="-80%" y="-80%" width="260%" height="260%">
                                    <feGaussianBlur stdDeviation="3.5" result="b"/>
                                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                                <filter id="hxb-f-amber" x="-80%" y="-80%" width="260%" height="260%">
                                    <feGaussianBlur stdDeviation="3" result="b"/>
                                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                                <filter id="hxb-f-red" x="-80%" y="-80%" width="260%" height="260%">
                                    <feGaussianBlur stdDeviation="4" result="b"/>
                                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                                <radialGradient id="hxb-congestion" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="var(--hxb-red)" stop-opacity="0.18"/>
                                    <stop offset="100%" stop-color="var(--hxb-red)" stop-opacity="0"/>
                                </radialGradient>
                                <radialGradient id="hxb-warning-grad" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="var(--hxb-amber)" stop-opacity="0.14"/>
                                    <stop offset="100%" stop-color="var(--hxb-amber)" stop-opacity="0"/>
                                </radialGradient>
                            </defs>

                            <!-- Static background (not transformed) -->
                            <g class="hxb-svg-grid">
                                <line x1="230" y1="0" x2="230" y2="390"/>
                                <line x1="490" y1="0" x2="490" y2="390"/>
                                <line x1="740" y1="0" x2="740" y2="390"/>
                                <line x1="0" y1="97"  x2="980" y2="97"/>
                                <line x1="0" y1="195" x2="980" y2="195"/>
                                <line x1="0" y1="293" x2="980" y2="293"/>
                            </g>
                            <text x="96"  y="17" text-anchor="middle" class="hxb-svg-text hxb-stage-lbl">PRODUCERS</text>
                            <text x="314" y="17" text-anchor="middle" class="hxb-svg-text hxb-stage-lbl">QUEUES</text>
                            <text x="564" y="17" text-anchor="middle" class="hxb-svg-text hxb-stage-lbl">WORKERS</text>
                            <text x="816" y="17" text-anchor="middle" class="hxb-svg-text hxb-stage-lbl">RESULTS</text>

                            <!-- Transformable viewport -->
                            <g :transform="viewportTransform">

                                <!-- halos for non-healthy nodes -->
                                <circle
                                    v-for="node in graphNodes.filter(n => n.status !== 'healthy')"
                                    :key="'halo-' + node.id"
                                    :cx="node.x + node.width / 2"
                                    :cy="node.y + node.height / 2"
                                    :r="node.status === 'critical' ? 54 : 46"
                                    :fill="node.status === 'critical' ? 'url(#hxb-congestion)' : 'url(#hxb-warning-grad)'"
                                >
                                    <animate attributeName="r" :values="node.status === 'critical' ? '48;64;48' : '40;54;40'" :dur="node.status === 'critical' ? '3s' : '4s'" repeatCount="indefinite"/>
                                    <animate attributeName="opacity" values="0.9;0.35;0.9" :dur="node.status === 'critical' ? '3s' : '4s'" repeatCount="indefinite"/>
                                </circle>

                                <!-- edges -->
                                <path
                                    v-for="edge in graphEdges"
                                    :id="'hxb-path-' + svgId(edge.id)"
                                    :key="'edge-' + edge.id"
                                    :d="edgePath(edge)"
                                    :stroke="edgeColor(edge.status)"
                                    :stroke-width="edge.status === 'critical' ? 1.9 : 1.5"
                                    fill="none"
                                    :opacity="edge.status === 'critical' ? 0.58 : 0.45"
                                />

                                <!-- edge rate labels -->
                                <text
                                    v-for="edge in graphEdges"
                                    :key="'elbl-' + edge.id"
                                    :x="edgeLabelPos(edge).x"
                                    :y="edgeLabelPos(edge).y"
                                    class="hxb-svg-text"
                                    font-size="8"
                                    :fill="edgeColor(edge.status)"
                                    :opacity="edge.status === 'critical' ? 0.85 : 0.65"
                                >{{ edgeDisplayLabel(edge) }}</text>

                                <!-- nodes -->
                                <g
                                    v-for="node in graphNodes"
                                    :key="node.id"
                                    class="hxb-svg-node"
                                    @click="selectNode(node.id)"
                                    @pointerdown.stop
                                >
                                    <rect
                                        :x="node.x" :y="node.y"
                                        :width="node.width" :height="node.height"
                                        rx="4"
                                        :fill="nodeFill(node)"
                                        :stroke="nodeStroke(node)"
                                        :stroke-width="nodeStrokeWidth(node)"
                                    >
                                        <animate v-if="node.status === 'critical'" attributeName="stroke-opacity" values="1;0.4;1" dur="2s" repeatCount="indefinite"/>
                                    </rect>
                                    <!-- selected fill overlay -->
                                    <rect v-if="node.id === selectedId" :x="node.x" :y="node.y" :width="node.width" :height="node.height" rx="4" fill="var(--hxb-violet)" opacity="0.07"/>
                                    <!-- accent bar -->
                                    <rect :x="node.x" :y="node.y" width="4" :height="node.height" rx="2" :fill="nodeAccent(node)" opacity="0.65"/>
                                    <text :x="node.x + node.width / 2" :y="node.y + 19" text-anchor="middle" class="hxb-svg-text" font-size="8.5" :fill="nodeAccent(node)" letter-spacing="0.5">{{ nodeKind(node) }}</text>
                                    <text :x="node.x + node.width / 2" :y="node.y + 32" text-anchor="middle" class="hxb-svg-text" font-size="9.5" fill="var(--hxb-text)">{{ node.label }}</text>
                                    <text :x="node.x + node.width / 2" :y="node.y + 44" text-anchor="middle" class="hxb-svg-text" font-size="8" :fill="nodeTextColor(node)">{{ node.sub }}</text>
                                </g>

                                <!-- particles -->
                                <circle
                                    v-for="p in particles"
                                    :key="p.id"
                                    :r="p.status === 'critical' ? 3 : 2.5"
                                    :fill="edgeColor(p.status)"
                                    :filter="particleFilter(p.status)"
                                >
                                    <animateMotion :dur="p.duration" :begin="p.delay" repeatCount="indefinite" calcMode="linear">
                                        <mpath :href="'#hxb-path-' + p.edgeId"></mpath>
                                    </animateMotion>
                                </circle>

                            </g><!-- /viewport -->
                        </svg>
                    </div>

                    <div class="hxb-legend">
                        <div class="hxb-legend-item"><span class="hxb-ldot producer"></span>Producer</div>
                        <div class="hxb-legend-item"><span class="hxb-ldot queue"></span>Queue</div>
                        <div class="hxb-legend-item"><span class="hxb-ldot worker"></span>Worker</div>
                        <div class="hxb-legend-item"><span class="hxb-ldot completed"></span>Completed</div>
                        <div class="hxb-legend-item"><span class="hxb-ldot failed"></span>Failed</div>
                        <span class="hxb-lsep"></span>
                        <div class="hxb-legend-item"><span class="hxb-lline healthy"></span>Healthy</div>
                        <div class="hxb-legend-item"><span class="hxb-lline warning"></span>Backpressure</div>
                        <div class="hxb-legend-item"><span class="hxb-lline critical"></span>Critical</div>
                    </div>
                </section>

                <!-- QUEUE TABLE -->
                <section class="hxb-panel">
                    <div class="hxb-panel-head">
                        <span class="hxb-panel-title">Queues</span>
                        <span class="hxb-panel-sub">{{ filteredQueues.length }} queue{{ filteredQueues.length === 1 ? '' : 's' }}</span>
                    </div>
                    <div class="hxb-table-wrap">
                        <table class="hxb-table">
                            <thead>
                                <tr>
                                    <th>Queue</th><th>Connection</th><th>Driver</th>
                                    <th class="r">Pending</th><th class="r">Delayed</th><th class="r">Wait</th>
                                    <th class="r">Procs</th><th class="r">Throughput</th><th class="r">Failed</th><th class="r">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="queue in filteredQueues"
                                    :key="queue.connection + ':' + queue.name"
                                    :class="{ selected: selectedId === (findQueueNode(queue)?.id ?? queueNodeId(queue)) }"
                                    @click="selectNode(findQueueNode(queue)?.id ?? queueNodeId(queue))"
                                >
                                    <td><span class="hxb-qname"><span class="hxb-sdot" :class="'s-' + queueStatus(queue)"></span>{{ queue.name }}</span></td>
                                    <td class="dim">{{ queue.connection }}</td>
                                    <td><span class="hxb-driver" :class="'driver-' + queue.driver">{{ queue.driver }}</span></td>
                                    <td class="r" :class="{ warn: queue.pending > 100, danger: queue.pending > 500 }">{{ formatNumber(queue.pending) }}</td>
                                    <td class="r dim">{{ formatNumber(queue.delayed) }}</td>
                                    <td class="r" :class="{ warn: queue.wait_seconds >= 10, danger: queue.wait_seconds >= 30 }">{{ metricValue(queue.wait_seconds, 's') }}</td>
                                    <td class="r">{{ formatNumber(queue.processes) }}</td>
                                    <td class="r ok">{{ formatRate(queue.throughput_per_minute) }}</td>
                                    <td class="r" :class="{ danger: (queue.failed ?? 0) > 0 }">{{ formatNumber(queue.failed ?? 0) }}</td>
                                    <td class="r"><span class="hxb-badge" :class="'b-' + queueStatus(queue)">{{ statusLabel(queueStatus(queue)) }}</span></td>
                                </tr>
                                <tr v-if="filteredQueues.length === 0">
                                    <td colspan="10" class="hxb-empty-row">{{ filterText ? 'No queues match the filter.' : 'No queues found.' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- ACTIVITY -->
                <section class="hxb-panel">
                    <div class="hxb-panel-head">
                        <span class="hxb-panel-title">Activity</span>
                        <span class="hxb-panel-badge">recent events</span>
                    </div>
                    <div class="hxb-activity-scroll">
                        <div class="hxb-activity-item" v-for="(event, index) in flow?.events ?? []" :key="index">
                            <span class="hxb-sdot" :class="'s-' + event.status" style="margin-top:4px;flex-shrink:0"></span>
                            <div>
                                <div class="hxb-activity-label">{{ event.label }}</div>
                                <div class="hxb-activity-meta">{{ index === 0 ? 'now' : index * 9 + 's ago' }}</div>
                            </div>
                        </div>
                        <div class="hxb-activity-item dim" v-if="(flow?.events ?? []).length === 0">No recent flow events.</div>
                    </div>
                </section>

            </div><!-- /left -->

            <!-- INSPECTOR -->
            <aside class="hxb-inspector">
                <section class="hxb-panel">
                    <div class="hxb-panel-head">
                        <span class="hxb-panel-title">Inspector</span>
                        <span class="hxb-badge" :class="'b-' + selectedInspector.node.status" style="margin-left:auto">{{ statusLabel(selectedInspector.node.status) }}</span>
                    </div>

                    <div class="hxb-insp-top">
                        <div class="hxb-insp-name">{{ selectedInspector.node.label }}</div>
                        <div class="hxb-insp-type">
                            <span>{{ nodeKind(selectedInspector.node) }}</span>
                            <span class="hxb-con-tag">{{ selectedInspector.queue ? selectedInspector.queue.connection + ' · ' + selectedInspector.queue.name : selectedInspector.node.id }}</span>
                        </div>
                    </div>

                    <div class="hxb-insp-sec">
                        <div class="hxb-insp-sec-title">Metrics</div>
                        <div class="hxb-metric-row" v-for="m in selectedInspector.metrics" :key="m[0]">
                            <span>{{ m[0] }}</span><strong>{{ m[1] }}</strong>
                        </div>
                    </div>

                    <div class="hxb-insp-sec">
                        <div class="hxb-insp-sec-title">Incoming</div>
                        <div class="hxb-edge-row" v-for="edge in selectedInspector.incoming" :key="edge.id">
                            <span class="hxb-sdot" :class="'s-' + edge.status"></span>
                            <span class="hxb-edge-lbl">{{ graphNodeLookup[edge.source]?.label ?? edge.source }}</span>
                            <small>{{ formatRate(edge.rate_per_minute) }}</small>
                        </div>
                        <div class="hxb-empty" v-if="selectedInspector.incoming.length === 0">—</div>
                    </div>

                    <div class="hxb-insp-sec">
                        <div class="hxb-insp-sec-title">Outgoing</div>
                        <div class="hxb-edge-row" v-for="edge in selectedInspector.outgoing" :key="edge.id">
                            <span class="hxb-sdot" :class="'s-' + edge.status"></span>
                            <span class="hxb-edge-lbl">{{ graphNodeLookup[edge.target]?.label ?? edge.target }}</span>
                            <small>{{ formatRate(edge.rate_per_minute) }}</small>
                        </div>
                        <div class="hxb-empty" v-if="selectedInspector.outgoing.length === 0">—</div>
                    </div>

                    <div class="hxb-action-block" :class="'action-' + selectedInspector.action.type">
                        <div class="hxb-action-title">{{ selectedInspector.action.title }}</div>
                        <div class="hxb-action-text">{{ selectedInspector.action.text }}</div>
                    </div>
                </section>
            </aside>
        </div><!-- /main -->

    </div>
</template>

<style scoped>
    /* ═══ LIGHT PALETTE (default) ═══════════════════════════════════════════ */
    .hxb-live-flow {
        --hxb-bg:             #f3f4f6;
        --hxb-panel:          #ffffff;
        --hxb-card:           #f9fafb;
        --hxb-border:         #e5e7eb;
        --hxb-border-bright:  #d1d5db;
        --hxb-text:           #111827;
        --hxb-muted:          #4b5563;
        --hxb-dim:            #9ca3af;
        --hxb-canvas:         #f0f4fb;
        --hxb-hover:          #f3f4f6;
        --hxb-selected:       #f5f3ff;
        --hxb-selected-bar:   #7746ec;

        --hxb-cyan:           #0891b2;
        --hxb-cyan-dim:       rgba(8,145,178,.09);
        --hxb-cyan-border:    rgba(8,145,178,.22);
        --hxb-green:          #059669;
        --hxb-green-dim:      rgba(5,150,105,.09);
        --hxb-green-border:   rgba(5,150,105,.22);
        --hxb-amber:          #d97706;
        --hxb-amber-dim:      rgba(217,119,6,.09);
        --hxb-amber-border:   rgba(217,119,6,.22);
        --hxb-red:            #dc2626;
        --hxb-red-dim:        rgba(220,38,38,.09);
        --hxb-red-border:     rgba(220,38,38,.22);
        --hxb-violet:         #7746ec;
        --hxb-violet-dim:     rgba(119,70,236,.09);
        --hxb-blue:           #2563eb;
        --hxb-blue-dim:       rgba(37,99,235,.09);
        --hxb-blue-border:    rgba(37,99,235,.22);

        --hxb-node-producer-bg:     #eff6ff;
        --hxb-node-producer-stroke: #93c5fd;
        --hxb-node-queue-bg:        #f5f3ff;
        --hxb-node-queue-stroke:    #c4b5fd;
        --hxb-node-worker-bg:       #f0fdf4;
        --hxb-node-worker-stroke:   #86efac;
        --hxb-node-result-bg:       #ecfdf5;
        --hxb-node-result-stroke:   #6ee7b7;
        --hxb-node-warning-bg:      #fffbeb;
        --hxb-node-warning-stroke:  #fcd34d;
        --hxb-node-critical-bg:     #fef2f2;
        --hxb-node-critical-stroke: #fca5a5;

        --hxb-grid-line:      rgba(99,102,241,.07);
        --hxb-stage-fill:     rgba(75,85,99,.45);

        position: relative; z-index: 0;
        padding: 0 0 2rem;
        color: var(--hxb-text);
        font-family: ui-monospace, "Cascadia Code", "Fira Code", "SF Mono", Consolas, monospace;
        font-size: 12px;
        line-height: 1.5;
    }

    /* ═══ DARK PALETTE ═══════════════════════════════════════════════════════ */
    .hxb-live-flow.hxb-dark {
        --hxb-bg:             #0b0e14;
        --hxb-panel:          #111520;
        --hxb-card:           #161c2a;
        --hxb-border:         #1e2a3a;
        --hxb-border-bright:  #243044;
        --hxb-text:           #c8d6e8;
        --hxb-muted:          #6b7e96;
        --hxb-dim:            #3d4f66;
        --hxb-canvas:         #090c12;
        --hxb-hover:          #1a2235;
        --hxb-selected:       rgba(0,200,212,.06);
        --hxb-selected-bar:   #a78bfa;

        --hxb-cyan:           #00c8d4;
        --hxb-cyan-dim:       rgba(0,200,212,.12);
        --hxb-cyan-border:    rgba(0,200,212,.25);
        --hxb-green:          #22c878;
        --hxb-green-dim:      rgba(34,200,120,.12);
        --hxb-green-border:   rgba(34,200,120,.25);
        --hxb-amber:          #f0a030;
        --hxb-amber-dim:      rgba(240,160,48,.12);
        --hxb-amber-border:   rgba(240,160,48,.25);
        --hxb-red:            #e0404a;
        --hxb-red-dim:        rgba(224,64,74,.12);
        --hxb-red-border:     rgba(224,64,74,.25);
        --hxb-violet:         #a78bfa;
        --hxb-violet-dim:     rgba(167,139,250,.12);
        --hxb-blue:           #4a90d9;
        --hxb-blue-dim:       rgba(74,144,217,.12);
        --hxb-blue-border:    rgba(74,144,217,.25);

        --hxb-node-producer-bg:     #0c1d30;
        --hxb-node-producer-stroke: #1e6090;
        --hxb-node-queue-bg:        #0a1c2e;
        --hxb-node-queue-stroke:    #1a4a6e;
        --hxb-node-worker-bg:       #091e17;
        --hxb-node-worker-stroke:   #164a38;
        --hxb-node-result-bg:       #091e12;
        --hxb-node-result-stroke:   #1e5028;
        --hxb-node-warning-bg:      #1a1305;
        --hxb-node-warning-stroke:  #f0a030;
        --hxb-node-critical-bg:     #1e0a0c;
        --hxb-node-critical-stroke: #e0404a;

        --hxb-grid-line:      rgba(0,200,212,.04);
        --hxb-stage-fill:     rgba(107,126,150,.45);
    }


    /* ── PAGE CONTROLS ──────────────────────────────────────────────────── */
    .hxb-page-controls { display:flex; align-items:center; gap:8px; padding:0 0 10px; flex-wrap:wrap; }
    .hxb-btn, .hxb-source-badge, .hxb-qname, .hxb-legend-item, .hxb-edge-row, .hxb-insp-type { display:flex; align-items:center; }
    .hxb-spacer { flex:1; }
    .hxb-source-badge { gap:5px; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; }
    .hxb-source-mock   { background:var(--hxb-blue-dim);  border:1px solid var(--hxb-blue-border);  color:var(--hxb-blue); }
    .hxb-source-redis  { background:var(--hxb-red-dim);   border:1px solid var(--hxb-red-border);   color:var(--hxb-red); }
    .hxb-source-db     { background:var(--hxb-green-dim); border:1px solid var(--hxb-green-border); color:var(--hxb-green); }
    .hxb-pulse { width:6px; height:6px; border-radius:50%; background:currentColor; animation:hxb-blink 2s ease-in-out infinite; }
    .hxb-pulse-green { background:var(--hxb-green); }
    .hxb-ts { color:var(--hxb-muted); font-size:11px; white-space:nowrap; }
    .hxb-controls { gap:6px; flex-wrap:wrap; }
    .hxb-ctl, .hxb-btn { height:27px; border-radius:4px; border:1px solid var(--hxb-border); background:var(--hxb-panel); color:var(--hxb-text); font-family:inherit; font-size:11px; outline:none; }
    .hxb-ctl { padding:4px 9px; }
    .hxb-ctl[type="text"] { width:170px; }
    .hxb-ctl::placeholder { color:var(--hxb-dim); }
    .hxb-btn { gap:5px; padding:4px 9px; color:var(--hxb-muted); cursor:pointer; transition:all .15s; }
    .hxb-btn:hover, .hxb-btn.active { border-color:var(--hxb-cyan); color:var(--hxb-cyan); background:var(--hxb-cyan-dim); }

    /* ── DEMO NOTICE ──────────────────────────────────────────────────────── */
    .hxb-demo-notice { display:flex; align-items:center; gap:7px; padding:7px 12px; margin:8px 0 0; background:var(--hxb-amber-dim); border:1px solid var(--hxb-amber-border); border-radius:4px; font-size:11px; color:var(--hxb-amber); }

    /* ── KPI ──────────────────────────────────────────────────────────────── */
    .hxb-kpi-strip { display:grid; grid-template-columns:repeat(6,1fr); gap:7px; padding:10px 0; border-bottom:1px solid var(--hxb-border); }
    .hxb-kpi { background:var(--hxb-panel); border:1px solid var(--hxb-border); border-radius:4px; padding:9px 12px 8px; }
    .hxb-kpi-label { font-size:9.5px; font-weight:600; letter-spacing:.09em; text-transform:uppercase; color:var(--hxb-muted); margin-bottom:5px; }
    .hxb-kpi-value { font-size:21px; font-weight:700; line-height:1; font-variant-numeric:tabular-nums; }
    .hxb-kpi-sub { font-size:9.5px; color:var(--hxb-dim); margin-top:3px; }
    .hxb-kpi.pending    .hxb-kpi-value { color:var(--hxb-cyan); }
    .hxb-kpi.processing .hxb-kpi-value { color:var(--hxb-blue); }
    .hxb-kpi.delayed    .hxb-kpi-value { color:var(--hxb-amber); }
    .hxb-kpi.failed     .hxb-kpi-value { color:var(--hxb-red); }
    .hxb-kpi.throughput .hxb-kpi-value { color:var(--hxb-green); }

    /* ── LOADING ──────────────────────────────────────────────────────────── */
    .hxb-loading { display:flex; align-items:center; justify-content:center; gap:10px; padding:60px 20px; color:var(--hxb-muted); font-size:13px; }
    .hxb-loading-spin { animation:hxb-spin .9s linear infinite; }

    /* ── LAYOUT ───────────────────────────────────────────────────────────── */
    .hxb-main { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:10px; margin-top:10px; }
    .hxb-left { display:flex; flex-direction:column; gap:10px; min-width:0; }
    .hxb-panel { background:var(--hxb-panel); border:1px solid var(--hxb-border); border-radius:6px; overflow:hidden; }
    .hxb-panel-head { display:flex; align-items:center; gap:8px; padding:9px 13px; border-bottom:1px solid var(--hxb-border); background:var(--hxb-card); }
    .hxb-panel-title { font-size:10.5px; font-weight:700; letter-spacing:.09em; text-transform:uppercase; color:var(--hxb-muted); }
    .hxb-panel-sub { font-size:10px; color:var(--hxb-dim); }
    .hxb-panel-badge { margin-left:auto; padding:2px 6px; border-radius:3px; font-size:9.5px; background:var(--hxb-cyan-dim); color:var(--hxb-cyan); border:1px solid var(--hxb-cyan-border); font-weight:600; }

    /* ── VIEWPORT CONTROLS ────────────────────────────────────────────────── */
    .hxb-vp-ctrls { display:flex; align-items:center; gap:2px; margin-left:auto; margin-right:6px; }
    .hxb-vp-btn { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:3px; border:1px solid var(--hxb-border); background:var(--hxb-panel); color:var(--hxb-muted); cursor:pointer; transition:all .12s; padding:0; }
    .hxb-vp-btn:hover { border-color:var(--hxb-cyan); color:var(--hxb-cyan); background:var(--hxb-cyan-dim); }
    .hxb-vp-zoom { font-size:10px; color:var(--hxb-dim); min-width:32px; text-align:center; font-variant-numeric:tabular-nums; }

    /* ── FLOW CANVAS ──────────────────────────────────────────────────────── */
    .hxb-canvas-wrap { background:var(--hxb-canvas); overflow:hidden; user-select:none; }
    .hxb-flow-svg { width:100%; height:360px; display:block; }
    .hxb-svg-text { font-family:ui-monospace,"Cascadia Code","SF Mono",monospace; }
    .hxb-svg-node { cursor:pointer; }
    .hxb-svg-node:hover > rect:first-child { filter:brightness(1.06); }
    .hxb-svg-grid line { stroke:var(--hxb-grid-line); stroke-width:1; }
    .hxb-stage-lbl { fill:var(--hxb-stage-fill); font-size:8.5px; letter-spacing:1.5px; }

    /* ── LEGEND ───────────────────────────────────────────────────────────── */
    .hxb-legend { display:flex; align-items:center; gap:14px; padding:7px 13px; border-top:1px solid var(--hxb-border); background:var(--hxb-card); flex-wrap:wrap; }
    .hxb-legend-item { gap:5px; font-size:10px; color:var(--hxb-muted); }
    .hxb-ldot { width:8px; height:8px; border-radius:50%; }
    .hxb-ldot.producer  { background:var(--hxb-node-producer-stroke); }
    .hxb-ldot.queue     { background:var(--hxb-node-queue-stroke); }
    .hxb-ldot.worker    { background:var(--hxb-node-worker-stroke); }
    .hxb-ldot.completed { background:var(--hxb-green); }
    .hxb-ldot.failed    { background:var(--hxb-red); }
    .hxb-lline { width:18px; height:2px; border-radius:1px; }
    .hxb-lline.healthy  { background:var(--hxb-cyan); }
    .hxb-lline.warning  { background:var(--hxb-amber); }
    .hxb-lline.critical { background:var(--hxb-red); }
    .hxb-lsep { width:1px; height:12px; background:var(--hxb-border); }

    /* ── TABLE ────────────────────────────────────────────────────────────── */
    .hxb-table-wrap { overflow-x:auto; }
    .hxb-table { width:100%; border-collapse:collapse; }
    .hxb-table th, .hxb-table td { white-space:nowrap; font-variant-numeric:tabular-nums; }
    .hxb-table th { padding:7px 11px; text-align:left; font-size:9.5px; font-weight:700; letter-spacing:.09em; text-transform:uppercase; color:var(--hxb-dim); border-bottom:1px solid var(--hxb-border); background:var(--hxb-card); }
    .hxb-table td { padding:6px 11px; font-size:11px; color:var(--hxb-text); border-bottom:1px solid var(--hxb-border); }
    .hxb-table .r { text-align:right; }
    .hxb-table tbody tr { cursor:pointer; transition:background .1s; }
    .hxb-table tbody tr:last-child td { border-bottom:none; }
    .hxb-table tbody tr:hover { background:var(--hxb-hover); }
    .hxb-table tbody tr.selected { background:var(--hxb-selected); border-left:2px solid var(--hxb-selected-bar); }
    .hxb-qname { gap:6px; }
    .hxb-empty-row { text-align:center !important; color:var(--hxb-dim) !important; padding:20px !important; }
    .hxb-driver { padding:1px 5px; border-radius:3px; font-size:9px; font-weight:700; text-transform:uppercase; }
    .driver-redis    { background:var(--hxb-red-dim);   color:var(--hxb-red); }
    .driver-mysql,
    .driver-database { background:var(--hxb-blue-dim);  color:var(--hxb-blue); }
    .driver-pgsql    { background:var(--hxb-green-dim); color:var(--hxb-green); }

    /* ── STATUS ───────────────────────────────────────────────────────────── */
    .hxb-sdot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
    .s-healthy  { background:var(--hxb-green); }
    .s-warning  { background:var(--hxb-amber); }
    .s-critical { background:var(--hxb-red); animation:hxb-blink 1s ease-in-out infinite; }
    .dim    { color:var(--hxb-muted) !important; }
    .warn   { color:var(--hxb-amber) !important; }
    .danger { color:var(--hxb-red)   !important; }
    .ok     { color:var(--hxb-green) !important; }
    .hxb-badge { padding:2px 7px; border-radius:3px; font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
    .b-healthy  { background:var(--hxb-green-dim);  color:var(--hxb-green); }
    .b-warning  { background:var(--hxb-amber-dim);  color:var(--hxb-amber); }
    .b-critical { background:var(--hxb-red-dim);    color:var(--hxb-red); }

    /* ── ACTIVITY ─────────────────────────────────────────────────────────── */
    .hxb-activity-scroll { max-height:210px; overflow-y:auto; }
    .hxb-activity-item { display:flex; align-items:flex-start; gap:9px; padding:6px 13px; border-bottom:1px solid var(--hxb-border); transition:background .1s; }
    .hxb-activity-item:last-child { border-bottom:none; }
    .hxb-activity-item:hover { background:var(--hxb-hover); }
    .hxb-activity-label { font-size:11px; color:var(--hxb-text); line-height:1.4; }
    .hxb-activity-meta  { font-size:10px; color:var(--hxb-dim); margin-top:1px; }

    /* ── INSPECTOR ────────────────────────────────────────────────────────── */
    .hxb-inspector { position:sticky; top:10px; max-height:calc(100vh - 20px); overflow-y:auto; align-self:start; }
    .hxb-inspector .hxb-panel-head .hxb-badge { margin-left:auto; }
    .hxb-insp-top { padding:11px 13px; }
    .hxb-insp-name { font-size:13px; font-weight:700; color:var(--hxb-text); margin-bottom:4px; }
    .hxb-insp-type { gap:6px; font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:var(--hxb-muted); }
    .hxb-con-tag { padding:2px 6px; border-radius:3px; font-size:10px; background:var(--hxb-card); border:1px solid var(--hxb-border); color:var(--hxb-muted); text-transform:none; letter-spacing:0; }
    .hxb-insp-sec { padding:9px 13px; border-top:1px solid var(--hxb-border); }
    .hxb-insp-sec-title { font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--hxb-dim); margin-bottom:7px; }
    .hxb-metric-row { display:flex; justify-content:space-between; align-items:baseline; gap:10px; padding:2.5px 0; }
    .hxb-metric-row span { color:var(--hxb-muted); font-size:11px; text-transform:capitalize; }
    .hxb-metric-row strong { color:var(--hxb-text); font-size:11px; font-weight:500; font-variant-numeric:tabular-nums; }
    .hxb-edge-row { gap:6px; padding:3.5px 0; font-size:11px; }
    .hxb-edge-lbl { flex:1; color:var(--hxb-text); }
    .hxb-edge-row small, .hxb-empty { color:var(--hxb-dim); font-size:10px; }
    .hxb-action-block { margin:0 13px 13px; padding:9px 11px; border-radius:4px; background:var(--hxb-cyan-dim); border:1px solid var(--hxb-cyan-border); border-left:3px solid var(--hxb-cyan); }
    .hxb-action-block.action-warn     { background:var(--hxb-amber-dim); border-color:var(--hxb-amber-border); border-left-color:var(--hxb-amber); }
    .hxb-action-block.action-critical { background:var(--hxb-red-dim);   border-color:var(--hxb-red-border);   border-left-color:var(--hxb-red); }
    .hxb-action-title { font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; margin-bottom:5px; color:var(--hxb-cyan); }
    .action-warn     .hxb-action-title { color:var(--hxb-amber); }
    .action-critical .hxb-action-title { color:var(--hxb-red); }
    .hxb-action-text { font-size:11px; color:var(--hxb-muted); line-height:1.6; }

    /* ── ANIMATIONS ───────────────────────────────────────────────────────── */
    @keyframes hxb-blink { 0%,100%{opacity:1} 50%{opacity:.25} }
    @keyframes hxb-spin   { to{transform:rotate(360deg)} }
    .hxb-spinning { animation:hxb-spin .9s linear infinite; }

    /* ── RESPONSIVE ───────────────────────────────────────────────────────── */
    @media (max-width: 1120px) {
        .hxb-main { grid-template-columns:1fr; }
        .hxb-inspector { position:static; max-height:none; }
    }
    @media (max-width: 860px) { .hxb-kpi-strip { grid-template-columns:repeat(3,1fr); } }
    @media (max-width: 520px) {
        .hxb-kpi-strip { grid-template-columns:repeat(2,1fr); }
        .hxb-ctl[type="text"] { width:100%; }
    }

    ::-webkit-scrollbar { width:4px; height:4px; }
    ::-webkit-scrollbar-track { background:transparent; }
    ::-webkit-scrollbar-thumb { background:var(--hxb-border-bright); border-radius:2px; }
</style>
