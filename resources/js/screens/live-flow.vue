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
                panX: 0, panY: 0, zoom: 1, isPanning: false,
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
                const workerCount = Math.max(1, (this.flow?.nodes ?? []).filter(n => n.type === 'worker').slice(0, 4).length);
                const resultCount = Math.max(1, (this.flow?.nodes ?? []).filter(n => n.type === 'result').slice(0, 4).length);
                const maxCol = Math.max(queueCount, workerCount, resultCount);
                return Math.max(390, topPad + maxCol * (nodeH + gap) - gap + botPad);
            },

            graphNodes() {
                const H = this.svgHeight;
                const topPad = 44, botPad = 32;
                const qH = 50, wH = 46, rH = 50, pH = 52;
                const qYMin = topPad,     qYMax = H - qH - botPad;
                const wYMin = topPad + 4, wYMax = H - wH - botPad - 4;
                const rYMin = topPad,     rYMax = H - rH - botPad;
                const midY = H / 2;

                const queues = this.filteredQueues.map((queue, i) => {
                    const node = this.findQueueNode(queue);
                    return {
                        id: node?.id ?? this.queueNodeId(queue),
                        type: 'queue', label: queue.name,
                        sub: this.queueSubLabel(queue),
                        status: node?.status ?? this.queueStatus(queue),
                        x: 250, y: this.distributedY(i, this.filteredQueues.length, qYMin, qYMax),
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

                const workerList = (this.flow?.nodes ?? []).filter(n => n.type === 'worker').slice(0, 4);
                const workers = workerList.map((n, i, all) => ({
                    id: n.id, type: 'worker', label: n.label,
                    sub: `${this.formatNumber(n.metrics?.processes ?? this.summary.processing)} processes`,
                    status: n.status,
                    x: 500, y: this.distributedY(i, all.length || 1, wYMin, wYMax),
                    width: 128, height: wH, metrics: n.metrics ?? {},
                }));
                const workerNodes = workers.length ? workers : [{
                    id: 'workers', type: 'worker', label: 'workers',
                    sub: `${this.formatNumber(this.summary.processing)} active`,
                    status: 'healthy', x: 500, y: midY - wH / 2, width: 128, height: wH,
                    metrics: { processes: this.summary.processing },
                }];

                const resultList = (this.flow?.nodes ?? []).filter(n => n.type === 'result').slice(0, 4);
                const results = resultList.map((n, i, all) => ({
                    id: n.id, type: 'result', label: n.label,
                    sub: this.resultSubLabel(n), status: n.status,
                    x: 750, y: this.distributedY(i, all.length || 1, rYMin, rYMax),
                    width: 132, height: rH, metrics: n.metrics ?? {},
                }));

                const prodSpread = Math.min(120, H * 0.16);
                return [
                    {
                        id: 'producer-app', type: 'producer', label: this.appLabel,
                        sub: `${this.meta.environment ?? 'app'} · ${this.formatNumber(this.summary.current_throughput_per_minute ?? this.summary.throughput_per_minute)} current/m`,
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
                    ...queues, ...workerNodes, ...results,
                ];
            },

            graphNodeLookup() {
                return this.graphNodes.reduce((acc, n) => { acc[n.id] = n; return acc; }, {});
            },

            graphEdges() {
                const existing = (this.flow?.edges ?? []).filter(e => this.graphNodeLookup[e.source] && this.graphNodeLookup[e.target]);
                if (existing.length) return existing;

                const workers   = this.graphNodes.filter(n => n.type === 'worker');
                const results   = this.graphNodes.filter(n => n.type === 'result');
                const completed = results.find(n => n.label === 'completed') ?? results[0];
                const failed    = results.find(n => n.label === 'failed');
                const generated = [];

                this.graphNodes.filter(n => n.type === 'queue').forEach((q, i) => {
                    const w = workers[i % workers.length];
                    const producer = (q.status === 'critical' || q.status === 'warning') ? 'producer-scheduler' : 'producer-app';
                    generated.push(this.edge(producer, q.id, q.status, 'dispatch', q.metrics.current_throughput ?? q.metrics.throughput));
                    generated.push(this.edge(q.id, w.id, q.status, 'reserve', q.metrics.current_throughput ?? q.metrics.throughput));
                });
                if (completed) workers.forEach(w => generated.push(this.edge(w.id, completed.id, 'healthy', 'finish', this.summary.throughput_per_minute)));
                if (failed && Number(this.summary.failed ?? 0) > 0) generated.push(this.edge(workers[workers.length - 1].id, failed.id, 'critical', 'exception', this.summary.failed));
                return generated;
            },

            particles() {
                if (!this.live) return [];
                return this.graphEdges.filter(edge => {
                    if (Number(edge.rate_per_minute ?? 0) <= 0) return false;
                    const src = this.graphNodeLookup[edge.source];
                    const tgt = this.graphNodeLookup[edge.target];
                    const queueNode = src?.type === 'queue' ? src : tgt?.type === 'queue' ? tgt : null;
                    if (queueNode) {
                        const m = queueNode.metrics ?? {};
                        return Number(m.pending ?? 0) > 0 || Number(m.processes ?? 0) > 0;
                    }
                    if (tgt?.type === 'result' && tgt.label === 'failed') {
                        return Number(this.summary.failed ?? 0) > 0;
                    }
                    return true;
                }).flatMap((edge, ei) => {
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

            viewportTransform() { return `translate(${this.panX} ${this.panY}) scale(${this.zoom})`; },
            zoomLabel()         { return Math.round(this.zoom * 100) + '%'; },

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

            getSVGCoords(e) {
                const svg = this.$refs.flowSvg;
                if (!svg) return { x: e.clientX, y: e.clientY };
                const pt = svg.createSVGPoint();
                pt.x = e.clientX; pt.y = e.clientY;
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
            toggleLive()   { this.live = !this.live; },

            svgId(value) { return String(value).replace(/[^a-z0-9_-]+/gi, '-'); },

            metricValue(value, suffix = '') {
                if (value === null || value === undefined) return 'n/a';
                return this.formatNumber(value) + suffix;
            },

            formatNumber(value) {
                if (value === null || value === undefined) return 'n/a';
                if (typeof value === 'number' && !Number.isInteger(value)) return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
                return Number(value).toLocaleString();
            },

            formatRate(value)    { return (value === null || value === undefined) ? 'n/a' : `${this.formatNumber(value)}/m`; },

            formatDuration(value) {
                if (value === null || value === undefined) return 'n/a';
                const s = Number(value);
                if (s < 60) return `${s}s`;
                if (s < 3600) return `${Math.round(s / 60)}m`;
                if (s < 86400) return `${(s / 3600).toFixed(1)}h`;
                return `${(s / 86400).toFixed(1)}d`;
            },

            formatPercent(value) {
                if (value === null || value === undefined) return 'n/a';
                return `${this.formatNumber(value)}%`;
            },

            statusLabel(status) {
                return { healthy: 'healthy', warning: 'backpressure', critical: 'critical' }[status] ?? status;
            },

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

            throughputState(queue) {
                if (Number(queue.current_throughput_per_minute ?? 0) > 0) return 'active';
                if (Number(queue.throughput_per_minute ?? 0) > 0) return 'last measured';
                return 'idle';
            },

            resultSubLabel(node) {
                if (node.label === 'failed')  return `${this.formatNumber(this.summary.failed)} failed`;
                if (node.label === 'delayed') return `${this.formatNumber(this.summary.delayed)} delayed`;
                return `${this.formatNumber(this.summary.completed)} completed`;
            },

            nodeFill(node) {
                if (node.status === 'critical') return 'var(--lf-node-critical-bg)';
                if (node.status === 'warning')  return 'var(--lf-node-warning-bg)';
                return { producer: 'var(--lf-node-producer-bg)', queue: 'var(--lf-node-queue-bg)', worker: 'var(--lf-node-worker-bg)', result: 'var(--lf-node-result-bg)' }[node.type] ?? 'var(--lf-node-queue-bg)';
            },

            nodeStroke(node) {
                if (node.id === this.selectedId) return 'var(--lf-violet)';
                if (node.status === 'critical')  return 'var(--lf-node-critical-stroke)';
                if (node.status === 'warning')   return 'var(--lf-node-warning-stroke)';
                return { producer: 'var(--lf-node-producer-stroke)', queue: 'var(--lf-node-queue-stroke)', worker: 'var(--lf-node-worker-stroke)', result: 'var(--lf-node-result-stroke)' }[node.type] ?? 'var(--lf-node-queue-stroke)';
            },

            nodeStrokeWidth(node) { return node.id === this.selectedId ? 2.2 : 1.5; },

            nodeAccent(node) {
                if (node.status === 'critical') return 'var(--lf-red)';
                if (node.status === 'warning')  return 'var(--lf-amber)';
                return { producer: 'var(--lf-blue)', queue: 'var(--lf-violet)', worker: 'var(--lf-green)', result: 'var(--lf-green)' }[node.type] ?? 'var(--lf-violet)';
            },

            nodeTextColor(node) {
                if (node.status === 'critical') return 'var(--lf-red)';
                if (node.status === 'warning')  return 'var(--lf-amber)';
                return 'var(--lf-muted)';
            },

            nodeKind(node) {
                return { producer: 'PRODUCER', queue: 'QUEUE', worker: 'WORKER', result: node.label?.toUpperCase?.() ?? 'RESULT' }[node.type] ?? node.type?.toUpperCase?.();
            },

            edgeColor(status) {
                return { healthy: 'var(--lf-cyan)', warning: 'var(--lf-amber)', critical: 'var(--lf-red)' }[status] ?? 'var(--lf-cyan)';
            },

            particleFilter(status) {
                if (!this.isDark) return 'none';
                return { healthy: 'url(#lf-f-cyan)', warning: 'url(#lf-f-amber)', critical: 'url(#lf-f-red)' }[status] ?? 'url(#lf-f-cyan)';
            },

            particleDuration(status) {
                return { healthy: 1.7, warning: 2.6, critical: 3.2 }[status] ?? 2;
            },

            edgeDisplayLabel(edge) {
                const rate = Number(edge.rate_per_minute ?? 0);
                if (rate <= 0) return ['dispatch', 'reserve', 'finish'].includes(edge.label) ? 'idle' : edge.label;
                return this.formatRate(rate);
            },

            inspectorMetrics(node, queue) {
                if (queue) return [
                    ['Source', queue.source ?? queue.driver], ['Connection', queue.connection],
                    ['Storage', queue.storage_connection ?? 'n/a'], ['Driver', queue.driver],
                    ['Pending', this.formatNumber(queue.pending)], ['Delayed', this.formatNumber(queue.delayed)],
                    ['Oldest pending', this.formatDuration(queue.oldest_pending_seconds ?? queue.wait_seconds)],
                    ['Wait', this.metricValue(queue.wait_seconds, 's')], ['Processes', this.formatNumber(queue.processes)],
                    ['Current rate', this.formatRate(queue.current_throughput_per_minute)],
                    ['Last measured', this.formatRate(queue.throughput_per_minute)],
                    ['Flow state', this.throughputState(queue)],
                    ['Drain ETA', this.formatDuration(queue.estimated_drain_seconds)],
                    ['Attempts', this.formatNumber(queue.attempts ?? 0)],
                    ['Failed', this.formatNumber(queue.failed ?? 0)],
                    ['Failure rate', this.formatPercent(queue.failure_rate)],
                    ['Latest error', queue.latest_error ?? 'none'],
                ];
                return Object.entries(node.metrics ?? {}).map(([k, v]) => [k.replace(/_/g, ' '), this.formatNumber(v)]);
            },

            suggestedAction(node, queue) {
                if (node.status === 'critical') return { type: 'critical', title: 'Immediate Action', text: queue ? this.criticalActionText(queue) : 'Failures above normal. Inspect failed job payloads.' };
                if (node.status === 'warning')  return { type: 'warn', title: 'Suggested Action', text: queue ? `${queue.name} is showing backpressure. Watch wait time and consider increasing process capacity.` : 'This node is under pressure. Monitor incoming rates.' };
                return { type: 'ok', title: 'Status', text: 'Node is operating normally. No action required.' };
            },

            criticalActionText(queue) {
                if (queue.latest_error) {
                    return `${queue.name} has failed jobs. Latest error: ${queue.latest_error}`;
                }

                if (Number(queue.pending ?? 0) > 0 && Number(queue.processes ?? 0) === 0) {
                    return `${queue.name} has pending jobs but no active workers. Add this queue to Horizon supervisor queues and restart Horizon.`;
                }

                return `Backlog is critical on ${queue.name}. Scale workers or reduce dispatch rate.`;
            },
        },
    }
</script>

<template>
    <div>
        <poll @poll="refreshFlowPeriodically" :interval="5" />

        <!-- Overview card -->
        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center gap-3 flex-wrap">
                <h2 class="h6 m-0">Overview</h2>

                <span v-if="flow" class="badge lf-source-badge" :class="'lf-source-' + sourceClass">
                    <span class="lf-pulse me-1"></span>{{ sourceLabel }}
                </span>
                <small v-if="generatedAt" class="text-muted">{{ generatedAt }}</small>

                <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
                    <input
                        v-model="filterText"
                        type="text"
                        class="form-control form-control-sm"
                        placeholder="Filter queues…"
                        style="width:160px"
                    >
                    <select v-model="timeRange" class="form-select form-select-sm" style="width:auto">
                        <option>Last 5m</option>
                        <option>Last 15m</option>
                        <option>Last 1h</option>
                        <option>Last 6h</option>
                        <option>Last 24h</option>
                    </select>
                    <button class="btn btn-sm btn-muted" type="button" @click="refreshFlowPeriodically">
                        <svg
                            :class="{ 'lf-spin': refreshing }"
                            width="12" height="12" viewBox="0 0 12 12" fill="none"
                            style="vertical-align:-1px;margin-right:3px"
                        >
                            <path d="M10.5 2A5 5 0 1 0 11 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            <path d="M10.5 2V5H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Refresh
                    </button>
                    <button
                        class="btn btn-sm d-flex align-items-center gap-1"
                        :class="live ? 'btn-success' : 'btn-muted'"
                        type="button"
                        @click="toggleLive"
                    >
                        <span v-if="live" class="lf-pulse lf-pulse-live"></span>
                        Live
                    </button>
                </div>
            </div>

            <!-- Demo notice -->
            <div
                v-if="ready && isMock"
                class="alert alert-warning d-flex align-items-center gap-2 mb-0 rounded-0 border-start-0 border-end-0 border-top-0"
                style="font-size:.85rem"
            >
                <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0">
                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                </svg>
                Demo data — not connected to a real queue. Configure a Redis or database connection to see live telemetry.
            </div>

            <!-- KPI grid -->
            <div class="card-bg-secondary">
                <div class="d-flex">
                    <div class="w-25">
                        <div class="p-4">
                            <small class="text-muted fw-bold">Pending</small>
                            <p class="h4 mt-2 mb-0 text-primary">{{ metricValue(summary.pending) }}</p>
                            <small class="text-muted">across {{ formatNumber(queues.length) }} queues</small>
                        </div>
                    </div>
                    <div class="w-25">
                        <div class="p-4">
                            <small class="text-muted fw-bold">Processing</small>
                            <p class="h4 mt-2 mb-0">{{ metricValue(summary.processing) }}</p>
                            <small class="text-muted">active workers</small>
                        </div>
                    </div>
                    <div class="w-25">
                        <div class="p-4">
                            <small class="text-muted fw-bold">Delayed</small>
                            <p class="h4 mt-2 mb-0" :class="(summary.delayed ?? 0) > 0 ? 'text-warning' : ''">{{ metricValue(summary.delayed) }}</p>
                            <small class="text-muted">scheduled</small>
                        </div>
                    </div>
                    <div class="w-25">
                        <div class="p-4">
                            <small class="text-muted fw-bold">Failed</small>
                            <p class="h4 mt-2 mb-0" :class="(summary.failed ?? 0) > 0 ? 'text-danger' : ''">{{ metricValue(summary.failed) }}</p>
                            <small class="text-muted">{{ timeRange.toLowerCase() }}</small>
                        </div>
                    </div>
                </div>
                <div class="d-flex">
                    <div class="w-25">
                        <div class="p-4">
                            <small class="text-muted fw-bold">Current Flow</small>
                            <p class="h4 mt-2 mb-0 text-success">{{ metricValue(summary.current_throughput_per_minute ?? summary.throughput_per_minute) }}</p>
                            <small class="text-muted">jobs / min</small>
                        </div>
                    </div>
                    <div class="w-25">
                        <div class="p-4">
                            <small class="text-muted fw-bold">Avg Wait</small>
                            <p class="h4 mt-2 mb-0">{{ metricValue(summary.average_wait_seconds, 's') }}</p>
                            <small class="text-muted">queue latency</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loading state -->
        <div class="d-flex align-items-center justify-content-center py-5 text-muted" v-if="!ready">
            <svg class="lf-spin me-2" width="18" height="18" viewBox="0 0 20 20" fill="none">
                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5" stroke-opacity="0.3"/>
                <path d="M10 2a8 8 0 0 1 8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            Loading live flow…
        </div>

        <!-- Main layout -->
        <div v-if="ready" class="row mt-4">
            <div class="col-12 col-lg-8 col-xl-9">

                <!-- Flow Graph -->
                <div class="card overflow-hidden">
                    <div class="card-header d-flex align-items-center gap-2">
                        <h2 class="h6 m-0">Flow Graph</h2>
                        <small class="text-muted">{{ graphNodes.length }} nodes · {{ graphEdges.length }} edges</small>

                        <div class="d-flex align-items-center gap-1 ms-auto">
                            <button class="btn btn-sm btn-muted lf-vp-btn" @click="zoomOut" title="Zoom out">
                                <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><line x1="2" y1="5" x2="8" y2="5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </button>
                            <small class="text-muted lf-zoom-label">{{ zoomLabel }}</small>
                            <button class="btn btn-sm btn-muted lf-vp-btn" @click="zoomIn" title="Zoom in">
                                <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><line x1="5" y1="2" x2="5" y2="8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="2" y1="5" x2="8" y2="5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </button>
                            <button class="btn btn-sm btn-muted lf-vp-btn" @click="resetView" title="Reset view">
                                <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><rect x="1" y="1" width="3" height="3" rx="0.5" stroke="currentColor" stroke-width="1.2"/><rect x="6" y="1" width="3" height="3" rx="0.5" stroke="currentColor" stroke-width="1.2"/><rect x="1" y="6" width="3" height="3" rx="0.5" stroke="currentColor" stroke-width="1.2"/><rect x="6" y="6" width="3" height="3" rx="0.5" stroke="currentColor" stroke-width="1.2"/></svg>
                            </button>
                        </div>

                        <span class="badge bg-success ms-1">live</span>
                    </div>

                    <div class="lf-canvas-wrap" :class="{ 'lf-dark': isDark }">
                        <svg
                            ref="flowSvg"
                            class="lf-flow-svg"
                            :viewBox="'0 0 980 ' + svgHeight"
                            xmlns="http://www.w3.org/2000/svg"
                            :style="{ cursor: isPanning ? 'grabbing' : 'grab' }"
                            @pointerdown="onCanvasPointerDown"
                            @pointermove="onCanvasPointerMove"
                            @pointerup="onCanvasPointerUp"
                            @pointerleave="onCanvasPointerUp"
                            @wheel.prevent="onCanvasWheel"
                        >
                            <defs>
                                <filter id="lf-f-cyan" x="-80%" y="-80%" width="260%" height="260%">
                                    <feGaussianBlur stdDeviation="3.5" result="b"/>
                                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                                <filter id="lf-f-amber" x="-80%" y="-80%" width="260%" height="260%">
                                    <feGaussianBlur stdDeviation="3" result="b"/>
                                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                                <filter id="lf-f-red" x="-80%" y="-80%" width="260%" height="260%">
                                    <feGaussianBlur stdDeviation="4" result="b"/>
                                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                                <radialGradient id="lf-congestion" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="var(--lf-red)" stop-opacity="0.18"/>
                                    <stop offset="100%" stop-color="var(--lf-red)" stop-opacity="0"/>
                                </radialGradient>
                                <radialGradient id="lf-warning-grad" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="var(--lf-amber)" stop-opacity="0.14"/>
                                    <stop offset="100%" stop-color="var(--lf-amber)" stop-opacity="0"/>
                                </radialGradient>
                            </defs>

                            <!-- Grid (static, not transformed) -->
                            <g class="lf-svg-grid">
                                <line x1="230" y1="0" x2="230" :y2="svgHeight"/>
                                <line x1="490" y1="0" x2="490" :y2="svgHeight"/>
                                <line x1="740" y1="0" x2="740" :y2="svgHeight"/>
                                <line x1="0" :y1="svgHeight * 0.25" x2="980" :y2="svgHeight * 0.25"/>
                                <line x1="0" :y1="svgHeight * 0.50" x2="980" :y2="svgHeight * 0.50"/>
                                <line x1="0" :y1="svgHeight * 0.75" x2="980" :y2="svgHeight * 0.75"/>
                            </g>
                            <text x="96"  y="17" text-anchor="middle" class="lf-svg-text lf-stage-lbl">PRODUCERS</text>
                            <text x="314" y="17" text-anchor="middle" class="lf-svg-text lf-stage-lbl">QUEUES</text>
                            <text x="564" y="17" text-anchor="middle" class="lf-svg-text lf-stage-lbl">WORKERS</text>
                            <text x="816" y="17" text-anchor="middle" class="lf-svg-text lf-stage-lbl">RESULTS</text>

                            <!-- Viewport group -->
                            <g :transform="viewportTransform">

                                <!-- status halos -->
                                <circle
                                    v-for="node in graphNodes.filter(n => n.status !== 'healthy')"
                                    :key="'halo-' + node.id"
                                    :cx="node.x + node.width / 2"
                                    :cy="node.y + node.height / 2"
                                    :r="node.status === 'critical' ? 54 : 46"
                                    :fill="node.status === 'critical' ? 'url(#lf-congestion)' : 'url(#lf-warning-grad)'"
                                >
                                    <animate attributeName="r" :values="node.status === 'critical' ? '48;64;48' : '40;54;40'" :dur="node.status === 'critical' ? '3s' : '4s'" repeatCount="indefinite"/>
                                    <animate attributeName="opacity" values="0.9;0.35;0.9" :dur="node.status === 'critical' ? '3s' : '4s'" repeatCount="indefinite"/>
                                </circle>

                                <!-- edges -->
                                <path
                                    v-for="edge in graphEdges"
                                    :id="'lf-path-' + svgId(edge.id)"
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
                                    class="lf-svg-text"
                                    font-size="8"
                                    :fill="edgeColor(edge.status)"
                                    :opacity="edge.status === 'critical' ? 0.85 : 0.65"
                                >{{ edgeDisplayLabel(edge) }}</text>

                                <!-- nodes -->
                                <g
                                    v-for="node in graphNodes"
                                    :key="node.id"
                                    class="lf-svg-node"
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
                                    <rect v-if="node.id === selectedId" :x="node.x" :y="node.y" :width="node.width" :height="node.height" rx="4" fill="var(--lf-violet)" opacity="0.07"/>
                                    <rect :x="node.x" :y="node.y" width="4" :height="node.height" rx="2" :fill="nodeAccent(node)" opacity="0.65"/>
                                    <text :x="node.x + node.width / 2" :y="node.y + 19" text-anchor="middle" class="lf-svg-text" font-size="8.5" :fill="nodeAccent(node)" letter-spacing="0.5">{{ nodeKind(node) }}</text>
                                    <text :x="node.x + node.width / 2" :y="node.y + 32" text-anchor="middle" class="lf-svg-text" font-size="9.5" fill="var(--lf-text)">{{ node.label }}</text>
                                    <text :x="node.x + node.width / 2" :y="node.y + 44" text-anchor="middle" class="lf-svg-text" font-size="8" :fill="nodeTextColor(node)">{{ node.sub }}</text>
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
                                        <mpath :href="'#lf-path-' + p.edgeId"></mpath>
                                    </animateMotion>
                                </circle>

                            </g>
                        </svg>
                    </div>

                    <!-- Legend -->
                    <div class="card-footer d-flex align-items-center gap-3 flex-wrap py-2">
                        <div class="d-flex align-items-center gap-1 small text-muted"><span class="lf-ldot lf-ldot-producer"></span> Producer</div>
                        <div class="d-flex align-items-center gap-1 small text-muted"><span class="lf-ldot lf-ldot-queue"></span> Queue</div>
                        <div class="d-flex align-items-center gap-1 small text-muted"><span class="lf-ldot lf-ldot-worker"></span> Worker</div>
                        <div class="d-flex align-items-center gap-1 small text-muted"><span class="lf-ldot lf-ldot-completed"></span> Completed</div>
                        <div class="d-flex align-items-center gap-1 small text-muted"><span class="lf-ldot lf-ldot-failed"></span> Failed</div>
                        <div class="vr"></div>
                        <div class="d-flex align-items-center gap-1 small text-muted"><span class="lf-lline lf-lline-healthy"></span> Healthy</div>
                        <div class="d-flex align-items-center gap-1 small text-muted"><span class="lf-lline lf-lline-warning"></span> Backpressure</div>
                        <div class="d-flex align-items-center gap-1 small text-muted"><span class="lf-lline lf-lline-critical"></span> Critical</div>
                    </div>
                </div>

                <!-- Queue table -->
                <div class="card overflow-hidden mt-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h2 class="h6 m-0">Queues</h2>
                        <small class="text-muted">{{ filteredQueues.length }} queue{{ filteredQueues.length === 1 ? '' : 's' }}</small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Queue</th>
                                    <th>Source</th>
                                    <th>Connection</th>
                                    <th>Driver</th>
                                    <th class="text-end">Pending</th>
                                    <th class="text-end">Delayed</th>
                                    <th class="text-end">Oldest</th>
                                    <th class="text-end">Wait</th>
                                    <th class="text-end">Procs</th>
                                    <th class="text-end">Current</th>
                                    <th class="text-end">Last</th>
                                    <th class="text-end">ETA</th>
                                    <th class="text-end">Attempts</th>
                                    <th class="text-end">Failed</th>
                                    <th class="text-end">Fail %</th>
                                    <th class="text-end">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="queue in filteredQueues"
                                    :key="queue.driver + ':' + queue.connection + ':' + queue.name"
                                    :class="{ 'lf-row-selected': selectedId === (findQueueNode(queue)?.id ?? queueNodeId(queue)) }"
                                    style="cursor:pointer"
                                    @click="selectNode(findQueueNode(queue)?.id ?? queueNodeId(queue))"
                                >
                                    <td>
                                        <span class="d-flex align-items-center gap-2">
                                            <span class="lf-sdot" :class="'lf-s-' + queueStatus(queue)"></span>
                                            {{ queue.name }}
                                        </span>
                                    </td>
                                    <td class="text-muted">{{ queue.source ?? queue.driver }}</td>
                                    <td class="text-muted">{{ queue.connection }}</td>
                                    <td><span class="badge lf-driver-badge" :class="'lf-driver-' + queue.driver">{{ queue.driver }}</span></td>
                                    <td class="text-end" :class="{ 'text-warning': queue.pending > 100, 'text-danger': queue.pending > 500 }">{{ formatNumber(queue.pending) }}</td>
                                    <td class="text-end text-muted">{{ formatNumber(queue.delayed) }}</td>
                                    <td class="text-end" :class="{ 'text-warning': (queue.oldest_pending_seconds ?? 0) >= 10, 'text-danger': (queue.oldest_pending_seconds ?? 0) >= 30 }">{{ formatDuration(queue.oldest_pending_seconds ?? queue.wait_seconds) }}</td>
                                    <td class="text-end" :class="{ 'text-warning': queue.wait_seconds >= 10, 'text-danger': queue.wait_seconds >= 30 }">{{ metricValue(queue.wait_seconds, 's') }}</td>
                                    <td class="text-end text-muted">{{ formatNumber(queue.processes) }}</td>
                                    <td class="text-end" :class="{ 'text-success': (queue.current_throughput_per_minute ?? 0) > 0, 'text-muted': (queue.current_throughput_per_minute ?? 0) <= 0 }">{{ formatRate(queue.current_throughput_per_minute) }}</td>
                                    <td class="text-end text-muted">{{ formatRate(queue.throughput_per_minute) }}</td>
                                    <td class="text-end text-muted">{{ formatDuration(queue.estimated_drain_seconds) }}</td>
                                    <td class="text-end text-muted">{{ formatNumber(queue.attempts ?? 0) }}</td>
                                    <td class="text-end" :class="{ 'text-danger fw-semibold': (queue.failed ?? 0) > 0 }">{{ formatNumber(queue.failed ?? 0) }}</td>
                                    <td class="text-end" :class="{ 'text-danger': (queue.failure_rate ?? 0) > 0 }">{{ formatPercent(queue.failure_rate) }}</td>
                                    <td class="text-end">
                                        <span class="badge" :class="{
                                            'bg-success': queueStatus(queue) === 'healthy',
                                            'bg-warning text-dark': queueStatus(queue) === 'warning',
                                            'bg-danger': queueStatus(queue) === 'critical',
                                        }">{{ statusLabel(queueStatus(queue)) }}</span>
                                    </td>
                                </tr>
                                <tr v-if="filteredQueues.length === 0">
                                    <td colspan="16" class="text-center text-muted py-4">
                                        {{ filterText ? 'No queues match the filter.' : 'No queues found.' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Activity -->
                <div class="card overflow-hidden mt-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h2 class="h6 m-0">Activity</h2>
                        <span class="badge bg-secondary">recent events</span>
                    </div>
                    <div class="lf-activity-scroll">
                        <div
                            v-for="(event, index) in flow?.events ?? []"
                            :key="index"
                            class="d-flex align-items-start gap-3 px-3 py-2 border-bottom"
                        >
                            <span class="lf-sdot mt-1 flex-shrink-0" :class="'lf-s-' + event.status"></span>
                            <div>
                                <div class="small">{{ event.label }}</div>
                                <div class="small text-muted">{{ index === 0 ? 'now' : index * 9 + 's ago' }}</div>
                            </div>
                        </div>
                        <div class="px-3 py-2 small text-muted" v-if="(flow?.events ?? []).length === 0">
                            No recent flow events.
                        </div>
                    </div>
                </div>

            </div>

            <!-- Inspector -->
            <div class="col-12 col-lg-4 col-xl-3 mt-4 mt-lg-0">
                <div class="card overflow-hidden lf-inspector">
                    <div class="card-header d-flex align-items-center">
                        <h2 class="h6 m-0">Inspector</h2>
                        <span class="badge ms-auto" :class="{
                            'bg-success': selectedInspector.node.status === 'healthy',
                            'bg-warning text-dark': selectedInspector.node.status === 'warning',
                            'bg-danger': selectedInspector.node.status === 'critical',
                        }">{{ statusLabel(selectedInspector.node.status) }}</span>
                    </div>

                    <div class="p-3 border-bottom">
                        <div class="fw-semibold mb-1">{{ selectedInspector.node.label }}</div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <small class="text-muted text-uppercase fw-bold lf-kind-label">{{ nodeKind(selectedInspector.node) }}</small>
                            <span class="badge bg-secondary fw-normal lf-con-tag">{{ selectedInspector.queue ? selectedInspector.queue.connection + ' · ' + selectedInspector.queue.name : selectedInspector.node.id }}</span>
                        </div>
                    </div>

                    <div class="p-3 border-bottom">
                        <p class="lf-sec-title">Metrics</p>
                        <div
                            class="d-flex justify-content-between align-items-baseline gap-2 py-1 small"
                            v-for="m in selectedInspector.metrics"
                            :key="m[0]"
                        >
                            <span class="text-muted text-capitalize">{{ m[0] }}</span>
                            <strong class="fw-medium font-monospace text-end">{{ m[1] }}</strong>
                        </div>
                    </div>

                    <div class="p-3 border-bottom">
                        <p class="lf-sec-title">Incoming</p>
                        <div class="d-flex align-items-center gap-2 py-1 small" v-for="edge in selectedInspector.incoming" :key="edge.id">
                            <span class="lf-sdot flex-shrink-0" :class="'lf-s-' + edge.status"></span>
                            <span class="flex-grow-1">{{ graphNodeLookup[edge.source]?.label ?? edge.source }}</span>
                            <small class="text-muted">{{ edgeDisplayLabel(edge) }}</small>
                        </div>
                        <div class="small text-muted" v-if="selectedInspector.incoming.length === 0">—</div>
                    </div>

                    <div class="p-3 border-bottom">
                        <p class="lf-sec-title">Outgoing</p>
                        <div class="d-flex align-items-center gap-2 py-1 small" v-for="edge in selectedInspector.outgoing" :key="edge.id">
                            <span class="lf-sdot flex-shrink-0" :class="'lf-s-' + edge.status"></span>
                            <span class="flex-grow-1">{{ graphNodeLookup[edge.target]?.label ?? edge.target }}</span>
                            <small class="text-muted">{{ edgeDisplayLabel(edge) }}</small>
                        </div>
                        <div class="small text-muted" v-if="selectedInspector.outgoing.length === 0">—</div>
                    </div>

                    <div
                        class="alert mb-0 rounded-0 border-0 border-top p-3"
                        :class="{
                            'alert-success': selectedInspector.action.type === 'ok',
                            'alert-warning': selectedInspector.action.type === 'warn',
                            'alert-danger':  selectedInspector.action.type === 'critical',
                        }"
                    >
                        <p class="lf-sec-title mb-1">{{ selectedInspector.action.title }}</p>
                        <p class="small mb-0">{{ selectedInspector.action.text }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
    /* ── SVG CANVAS — light palette ──────────────────────────────── */
    .lf-canvas-wrap {
        overflow: hidden;
        user-select: none;
        background: var(--bs-secondary-bg, #f3f4f6);
        --lf-node-producer-bg:     rgba(13,  110, 253, 0.07);
        --lf-node-producer-stroke: rgba(13,  110, 253, 0.28);
        --lf-node-queue-bg:        rgba(119,  70, 236, 0.07);
        --lf-node-queue-stroke:    rgba(119,  70, 236, 0.28);
        --lf-node-worker-bg:       rgba( 25, 135,  84, 0.07);
        --lf-node-worker-stroke:   rgba( 25, 135,  84, 0.28);
        --lf-node-result-bg:       rgba( 25, 135,  84, 0.07);
        --lf-node-result-stroke:   rgba( 25, 135,  84, 0.28);
        --lf-node-warning-bg:      rgba(255, 193,   7, 0.10);
        --lf-node-warning-stroke:  #d97706;
        --lf-node-critical-bg:     rgba(220,  53,  69, 0.07);
        --lf-node-critical-stroke: #dc3545;
        --lf-grid-line:  rgba(119, 70, 236, 0.055);
        --lf-stage-fill: rgba(75, 85, 99, 0.50);
        --lf-text:   #111827;
        --lf-muted:  #6b7280;
        --lf-cyan:   #0891b2;
        --lf-amber:  #d97706;
        --lf-green:  #059669;
        --lf-red:    #dc2626;
        --lf-violet: #7746ec;
        --lf-blue:   #2563eb;
    }

    /* ── SVG CANVAS — dark palette ───────────────────────────────── */
    .lf-canvas-wrap.lf-dark {
        background: #090c12;
        --lf-node-producer-bg:     rgba(  0, 160, 210, 0.10);
        --lf-node-producer-stroke: rgba(  0, 160, 210, 0.30);
        --lf-node-queue-bg:        rgba(119,  70, 236, 0.10);
        --lf-node-queue-stroke:    rgba(119,  70, 236, 0.32);
        --lf-node-worker-bg:       rgba( 34, 200, 120, 0.10);
        --lf-node-worker-stroke:   rgba( 34, 200, 120, 0.30);
        --lf-node-result-bg:       rgba( 34, 200, 120, 0.10);
        --lf-node-result-stroke:   rgba( 34, 200, 120, 0.30);
        --lf-node-warning-bg:      rgba(240, 160,  48, 0.10);
        --lf-node-warning-stroke:  #f0a030;
        --lf-node-critical-bg:     rgba(224,  64,  74, 0.10);
        --lf-node-critical-stroke: #e0404a;
        --lf-grid-line:  rgba(0, 200, 212, 0.04);
        --lf-stage-fill: rgba(107, 126, 150, 0.45);
        --lf-text:   #c8d6e8;
        --lf-muted:  #6b7e96;
        --lf-cyan:   #00c8d4;
        --lf-amber:  #f0a030;
        --lf-green:  #22c878;
        --lf-red:    #e0404a;
        --lf-violet: #a78bfa;
        --lf-blue:   #4a90d9;
    }

    /* ── SVG ─────────────────────────────────────────────────────── */
    .lf-flow-svg     { width: 100%; height: auto; min-height: 280px; max-height: 660px; display: block; }
    .lf-svg-text     { font-family: ui-monospace, "SF Mono", "Cascadia Code", Consolas, monospace; }
    .lf-svg-node     { cursor: pointer; }
    .lf-svg-node:hover > rect:first-child { filter: brightness(1.06); }
    .lf-svg-grid line { stroke: var(--lf-grid-line); stroke-width: 1; }
    .lf-stage-lbl    { fill: var(--lf-stage-fill); font-size: 8.5px; letter-spacing: 1.5px; }

    /* ── INSPECTOR ───────────────────────────────────────────────── */
    .lf-inspector { position: sticky; top: 1rem; max-height: calc(100vh - 2rem); overflow-y: auto; }

    /* ── STATUS DOTS ─────────────────────────────────────────────── */
    .lf-sdot       { display: inline-block; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .lf-s-healthy  { background: #198754; }
    .lf-s-warning  { background: #d97706; }
    .lf-s-critical { background: #dc3545; animation: lf-blink 1s ease-in-out infinite; }

    /* ── SOURCE BADGE ────────────────────────────────────────────── */
    .lf-source-badge  { font-size: 10px; letter-spacing: .07em; font-weight: 700; }
    .lf-source-mock   { background: rgba( 13,110,253,.10); color: #0d6efd; border: 1px solid rgba( 13,110,253,.22); }
    .lf-source-auto   { background: rgba(119, 70,236,.10); color: #7746ec; border: 1px solid rgba(119, 70,236,.22); }
    .lf-source-redis  { background: rgba(220, 53, 69,.10); color: #dc3545; border: 1px solid rgba(220, 53, 69,.22); }
    .lf-source-db     { background: rgba( 25,135, 84,.10); color: #198754; border: 1px solid rgba( 25,135, 84,.22); }

    /* ── DRIVER BADGE ────────────────────────────────────────────── */
    .lf-driver-badge    { font-size: 9px; font-weight: 700; text-transform: uppercase; }
    .lf-driver-redis    { background: rgba(220,53,69,.10);  color: #dc3545; }
    .lf-driver-mysql,
    .lf-driver-database { background: rgba(13,110,253,.10); color: #0d6efd; }
    .lf-driver-pgsql    { background: rgba(25,135,84,.10);  color: #198754; }

    /* ── LEGEND ──────────────────────────────────────────────────── */
    .lf-ldot           { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
    .lf-ldot-producer  { background: rgba(13,110,253,.55); }
    .lf-ldot-queue     { background: rgba(119,70,236,.55); }
    .lf-ldot-worker    { background: rgba(25,135,84,.55); }
    .lf-ldot-completed { background: #198754; }
    .lf-ldot-failed    { background: #dc3545; }
    .lf-lline          { display: inline-block; width: 18px; height: 2px; border-radius: 1px; }
    .lf-lline-healthy  { background: #0891b2; }
    .lf-lline-warning  { background: #d97706; }
    .lf-lline-critical { background: #dc3545; }

    /* ── PULSE ───────────────────────────────────────────────────── */
    .lf-pulse      { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: lf-blink 2s ease-in-out infinite; }
    .lf-pulse-live { background: #fff; }

    /* ── TABLE ───────────────────────────────────────────────────── */
    .lf-row-selected { background: rgba(119,70,236,.06) !important; border-left: 2px solid #7746ec; }

    /* ── VIEWPORT CONTROLS ───────────────────────────────────────── */
    .lf-vp-btn   { width: 26px; height: 26px; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
    .lf-zoom-label { min-width: 36px; text-align: center; font-variant-numeric: tabular-nums; font-size: .75rem; }

    /* ── ACTIVITY ────────────────────────────────────────────────── */
    .lf-activity-scroll { max-height: 220px; overflow-y: auto; }
    .lf-activity-scroll > div:last-child { border-bottom: none !important; }

    /* ── INSPECTOR TYPOGRAPHY ────────────────────────────────────── */
    .lf-sec-title  { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--bs-secondary-color, #6c757d); margin-bottom: 0; }
    .lf-kind-label { font-size: .7rem; letter-spacing: .06em; }
    .lf-con-tag    { font-size: .7rem; font-weight: 400; }

    /* ── ANIMATIONS ──────────────────────────────────────────────── */
    @keyframes lf-blink { 0%,100%{opacity:1} 50%{opacity:.25} }
    @keyframes lf-spin  { to{transform:rotate(360deg)} }
    .lf-spin { animation: lf-spin .9s linear infinite; display: inline-block; }
</style>
