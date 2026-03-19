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
                        type: 'queue', label: queue.name, sub: this.queueSubLabel(queue),
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

            edgeGradients() {
                return this.graphEdges.map(edge => {
                    const s = this.graphNodeLookup[edge.source];
                    const t = this.graphNodeLookup[edge.target];
                    if (!s || !t) return null;
                    return {
                        id: `lf-grad-${this.svgId(edge.id)}`,
                        x1: s.x + s.width, y1: s.y + s.height / 2,
                        x2: t.x,           y2: t.y + t.height / 2,
                        c1: this.nodeGradColor(s, edge.status),
                        c2: this.nodeGradColor(t, edge.status),
                    };
                }).filter(Boolean);
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

            formatPercent(value) {
                if (value === null || value === undefined) return '—';
                return `${this.formatNumber(value)}%`;
            },

            statusLabel(status) {
                return { healthy: 'healthy', warning: 'warn', critical: 'critical' }[status] ?? status;
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
                return 'var(--lf-svg-muted)';
            },

            nodeKind(node) {
                return { producer: 'PRODUCER', queue: 'QUEUE', worker: 'WORKER', result: node.label?.toUpperCase?.() ?? 'RESULT' }[node.type] ?? node.type?.toUpperCase?.();
            },

            nodeGradColor(node, edgeStatus) {
                if (edgeStatus === 'critical') return 'var(--lf-red)';
                if (edgeStatus === 'warning')  return 'var(--lf-amber)';
                return { producer: 'var(--lf-blue)', queue: 'var(--lf-violet)', worker: 'var(--lf-green)', result: 'var(--lf-green)' }[node.type] ?? 'var(--lf-cyan)';
            },

            edgeColor(status) {
                return { healthy: 'var(--lf-cyan)', warning: 'var(--lf-amber)', critical: 'var(--lf-red)' }[status] ?? 'var(--lf-cyan)';
            },

            particleColor(status) {
                return { healthy: 'var(--lf-cyan)', warning: 'var(--lf-amber)', critical: 'var(--lf-red)' }[status] ?? 'var(--lf-cyan)';
            },

            particleFilter(status) {
                if (!this.isDark) return 'none';
                return { healthy: 'url(#lf-glow-cyan)', warning: 'url(#lf-glow-amber)', critical: 'url(#lf-glow-red)' }[status] ?? 'url(#lf-glow-cyan)';
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
        <poll @poll="refreshFlowPeriodically" :interval="5" />

        <!-- toolbar -->
        <div class="lf-toolbar">
            <span v-if="flow" class="lf-chip" :class="'lf-chip-' + sourceClass">
                <span class="lf-blink"></span>{{ sourceLabel }}
            </span>
            <span v-if="generatedAt" class="lf-ts">{{ generatedAt }}</span>
            <div class="lf-toolbar-gap"></div>
            <input v-model="filterText" type="text" class="lf-input" placeholder="filter queues…">
            <select v-model="timeRange" class="lf-select">
                <option>Last 5m</option><option>Last 15m</option>
                <option>Last 1h</option><option>Last 6h</option><option>Last 24h</option>
            </select>
            <button class="lf-btn" type="button" @click="refreshFlowPeriodically">
                <svg :class="{ 'lf-spin': refreshing }" width="11" height="11" viewBox="0 0 12 12" fill="none">
                    <path d="M10.5 2A5 5 0 1 0 11 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="M10.5 2V5H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                refresh
            </button>
            <button class="lf-btn" :class="{ 'lf-btn-live': live }" type="button" @click="toggleLive">
                <span class="lf-blink lf-blink-inline"></span>live
            </button>
        </div>

        <!-- demo notice -->
        <div class="lf-notice lf-notice-warn" v-if="ready && isMock">
            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0;opacity:.8">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            Demo data — configure a Redis or database connection to see live telemetry.
        </div>

        <!-- metrics strip -->
        <div class="lf-metrics">
            <div
                v-for="m in kpiMetrics"
                :key="m.key"
                class="lf-metric"
            >
                <span class="lf-metric-label">{{ m.label }}</span>
                <span class="lf-metric-value" :class="m.cls ? 'lf-val-' + m.cls : ''">{{ m.value }}</span>
                <span class="lf-metric-sub">{{ m.sub }}</span>
            </div>
        </div>

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

                <!-- flow graph -->
                <div class="lf-pane">
                    <div class="lf-pane-head">
                        <span class="lf-pane-title">Flow Graph</span>
                        <span class="lf-pane-meta">{{ graphNodes.length }} nodes · {{ graphEdges.length }} edges</span>
                        <div class="lf-vp">
                            <button class="lf-vp-btn" @click="zoomOut" title="Zoom out">
                                <svg width="9" height="9" viewBox="0 0 9 9" fill="none"><line x1="1.5" y1="4.5" x2="7.5" y2="4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                            </button>
                            <span class="lf-vp-pct">{{ zoomLabel }}</span>
                            <button class="lf-vp-btn" @click="zoomIn" title="Zoom in">
                                <svg width="9" height="9" viewBox="0 0 9 9" fill="none"><line x1="4.5" y1="1.5" x2="4.5" y2="7.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><line x1="1.5" y1="4.5" x2="7.5" y2="4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                            </button>
                            <button class="lf-vp-btn" @click="resetView" title="Reset view">
                                <svg width="9" height="9" viewBox="0 0 9 9" fill="none"><rect x="0.75" y="0.75" width="2.75" height="2.75" rx="0.5" stroke="currentColor" stroke-width="1.2"/><rect x="5.5" y="0.75" width="2.75" height="2.75" rx="0.5" stroke="currentColor" stroke-width="1.2"/><rect x="0.75" y="5.5" width="2.75" height="2.75" rx="0.5" stroke="currentColor" stroke-width="1.2"/><rect x="5.5" y="5.5" width="2.75" height="2.75" rx="0.5" stroke="currentColor" stroke-width="1.2"/></svg>
                            </button>
                        </div>
                        <span class="lf-live-tag">
                            <span class="lf-blink lf-blink-inline lf-blink-green"></span>live
                        </span>
                    </div>

                    <div class="lf-canvas" :class="{ 'lf-canvas-dark': isDark }">
                        <svg
                            ref="flowSvg"
                            class="lf-svg"
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
                                <!-- glow filters (dark mode only) -->
                                <filter id="lf-glow-cyan" x="-80%" y="-80%" width="260%" height="260%">
                                    <feGaussianBlur stdDeviation="3.5" result="b"/>
                                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                                <filter id="lf-glow-amber" x="-80%" y="-80%" width="260%" height="260%">
                                    <feGaussianBlur stdDeviation="3" result="b"/>
                                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                                <filter id="lf-glow-red" x="-80%" y="-80%" width="260%" height="260%">
                                    <feGaussianBlur stdDeviation="4" result="b"/>
                                    <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                                </filter>
                                <!-- status halos -->
                                <radialGradient id="lf-halo-red" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="var(--lf-red)" stop-opacity="0.18"/>
                                    <stop offset="100%" stop-color="var(--lf-red)" stop-opacity="0"/>
                                </radialGradient>
                                <radialGradient id="lf-halo-amber" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="var(--lf-amber)" stop-opacity="0.14"/>
                                    <stop offset="100%" stop-color="var(--lf-amber)" stop-opacity="0"/>
                                </radialGradient>
                                <!-- edge gradients -->
                                <linearGradient
                                    v-for="g in edgeGradients"
                                    :key="g.id"
                                    :id="g.id"
                                    :x1="g.x1" :y1="g.y1"
                                    :x2="g.x2" :y2="g.y2"
                                    gradientUnits="userSpaceOnUse"
                                >
                                    <stop offset="0%"   :stop-color="g.c1" stop-opacity="0.85"/>
                                    <stop offset="100%" :stop-color="g.c2" stop-opacity="0.50"/>
                                </linearGradient>
                            </defs>

                            <!-- grid (not transformed) -->
                            <g class="lf-svg-grid">
                                <line x1="230" y1="0" x2="230" :y2="svgHeight"/>
                                <line x1="490" y1="0" x2="490" :y2="svgHeight"/>
                                <line x1="740" y1="0" x2="740" :y2="svgHeight"/>
                            </g>
                            <text x="96"  y="17" text-anchor="middle" class="lf-stage">PRODUCERS</text>
                            <text x="314" y="17" text-anchor="middle" class="lf-stage">QUEUES</text>
                            <text x="564" y="17" text-anchor="middle" class="lf-stage">WORKERS</text>
                            <text x="816" y="17" text-anchor="middle" class="lf-stage">RESULTS</text>

                            <!-- viewport group -->
                            <g :transform="viewportTransform">

                                <!-- status halos -->
                                <circle
                                    v-for="node in graphNodes.filter(n => n.status !== 'healthy')"
                                    :key="'halo-' + node.id"
                                    :cx="node.x + node.width / 2"
                                    :cy="node.y + node.height / 2"
                                    :r="node.status === 'critical' ? 54 : 46"
                                    :fill="node.status === 'critical' ? 'url(#lf-halo-red)' : 'url(#lf-halo-amber)'"
                                >
                                    <animate attributeName="r" :values="node.status === 'critical' ? '48;64;48' : '40;54;40'" :dur="node.status === 'critical' ? '3s' : '4s'" repeatCount="indefinite"/>
                                    <animate attributeName="opacity" values="0.9;0.3;0.9" :dur="node.status === 'critical' ? '3s' : '4s'" repeatCount="indefinite"/>
                                </circle>

                                <!-- edges with gradient stroke -->
                                <path
                                    v-for="edge in graphEdges"
                                    :id="'lf-path-' + svgId(edge.id)"
                                    :key="'edge-' + edge.id"
                                    :d="edgePath(edge)"
                                    :stroke="'url(#lf-grad-' + svgId(edge.id) + ')'"
                                    :stroke-width="edge.status === 'critical' ? 2 : 1.6"
                                    stroke-linecap="round"
                                    fill="none"
                                    :opacity="edge.status === 'critical' ? 0.75 : 0.55"
                                />

                                <!-- edge rate labels -->
                                <text
                                    v-for="edge in graphEdges"
                                    :key="'el-' + edge.id"
                                    :x="edgeLabelPos(edge).x"
                                    :y="edgeLabelPos(edge).y"
                                    class="lf-svg-mono"
                                    font-size="7.5"
                                    :fill="edgeColor(edge.status)"
                                    :opacity="edge.status === 'critical' ? 0.9 : 0.65"
                                >{{ edgeDisplayLabel(edge) }}</text>

                                <!-- nodes -->
                                <g
                                    v-for="node in graphNodes"
                                    :key="node.id"
                                    class="lf-svg-node"
                                    @click="selectNode(node.id)"
                                    @pointerdown.stop
                                >
                                    <!-- shadow / selection glow -->
                                    <rect
                                        v-if="node.id === selectedId"
                                        :x="node.x - 3" :y="node.y - 3"
                                        :width="node.width + 6" :height="node.height + 6"
                                        rx="6" fill="none"
                                        stroke="var(--lf-violet)" stroke-width="1"
                                        stroke-opacity="0.4"
                                        :stroke-dasharray="'4 3'"
                                    />
                                    <!-- node body -->
                                    <rect
                                        :x="node.x" :y="node.y"
                                        :width="node.width" :height="node.height"
                                        rx="3"
                                        :fill="nodeFill(node)"
                                        :stroke="nodeStroke(node)"
                                        :stroke-width="nodeStrokeWidth(node)"
                                    >
                                        <animate v-if="node.status === 'critical'" attributeName="stroke-opacity" values="1;0.35;1" dur="2s" repeatCount="indefinite"/>
                                    </rect>
                                    <!-- selected highlight -->
                                    <rect v-if="node.id === selectedId" :x="node.x" :y="node.y" :width="node.width" :height="node.height" rx="3" fill="var(--lf-violet)" opacity="0.06"/>
                                    <!-- accent bar -->
                                    <rect :x="node.x" :y="node.y + 2" width="3" :height="node.height - 4" rx="1.5" :fill="nodeAccent(node)" opacity="0.7"/>
                                    <!-- labels -->
                                    <text :x="node.x + node.width / 2" :y="node.y + 18" text-anchor="middle" class="lf-svg-mono" font-size="8" :fill="nodeAccent(node)" letter-spacing="0.7">{{ nodeKind(node) }}</text>
                                    <text :x="node.x + node.width / 2" :y="node.y + 31" text-anchor="middle" class="lf-svg-mono" font-size="9.5" fill="var(--lf-svg-text)">{{ node.label }}</text>
                                    <text :x="node.x + node.width / 2" :y="node.y + 43" text-anchor="middle" class="lf-svg-mono" font-size="7.5" :fill="nodeTextColor(node)">{{ node.sub }}</text>
                                </g>

                                <!-- particles -->
                                <circle
                                    v-for="p in particles"
                                    :key="p.id"
                                    :r="p.status === 'critical' ? 2.8 : 2.2"
                                    :fill="particleColor(p.status)"
                                    :filter="particleFilter(p.status)"
                                >
                                    <animateMotion :dur="p.duration" :begin="p.delay" repeatCount="indefinite" calcMode="linear">
                                        <mpath :href="'#lf-path-' + p.edgeId"></mpath>
                                    </animateMotion>
                                </circle>

                            </g>
                        </svg>
                    </div>

                    <!-- legend -->
                    <div class="lf-legend">
                        <span class="lf-leg-item"><span class="lf-ld lf-ld-producer"></span>Producer</span>
                        <span class="lf-leg-item"><span class="lf-ld lf-ld-queue"></span>Queue</span>
                        <span class="lf-leg-item"><span class="lf-ld lf-ld-worker"></span>Worker</span>
                        <span class="lf-leg-item"><span class="lf-ld lf-ld-done"></span>Completed</span>
                        <span class="lf-leg-item"><span class="lf-ld lf-ld-fail"></span>Failed</span>
                        <span class="lf-leg-sep"></span>
                        <span class="lf-leg-item"><span class="lf-ll lf-ll-ok"></span>Healthy</span>
                        <span class="lf-leg-item"><span class="lf-ll lf-ll-warn"></span>Backpressure</span>
                        <span class="lf-leg-item"><span class="lf-ll lf-ll-crit"></span>Critical</span>
                    </div>
                </div>

                <!-- queue table -->
                <div class="lf-pane lf-pane-gap">
                    <div class="lf-pane-head">
                        <span class="lf-pane-title">Queues</span>
                        <span class="lf-pane-meta">{{ filteredQueues.length }} queue{{ filteredQueues.length === 1 ? '' : 's' }}</span>
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
                                    v-for="queue in filteredQueues"
                                    :key="queue.driver + ':' + queue.connection + ':' + queue.name"
                                    :class="{ 'lf-tbl-sel': selectedId === (findQueueNode(queue)?.id ?? queueNodeId(queue)) }"
                                    @click="selectNode(findQueueNode(queue)?.id ?? queueNodeId(queue))"
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
                                    <td class="r num" :class="{ crit: (queue.failed ?? 0) > 0 }">{{ formatNumber(queue.failed ?? 0) }}</td>
                                    <td class="r num" :class="{ warn: (queue.failure_rate ?? 0) > 0 }">{{ formatPercent(queue.failure_rate) }}</td>
                                    <td class="r">
                                        <span class="lf-status" :class="'lf-status-' + queueStatus(queue)">{{ statusLabel(queueStatus(queue)) }}</span>
                                    </td>
                                </tr>
                                <tr v-if="filteredQueues.length === 0">
                                    <td colspan="16" class="lf-empty">{{ filterText ? 'No queues match the filter.' : 'No queues found.' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- activity -->
                <div class="lf-pane lf-pane-gap">
                    <div class="lf-pane-head">
                        <span class="lf-pane-title">Activity</span>
                        <span class="lf-tag">recent</span>
                    </div>
                    <div class="lf-activity">
                        <div
                            v-for="(event, i) in flow?.events ?? []"
                            :key="i"
                            class="lf-event"
                        >
                            <span class="lf-event-time">{{ i === 0 ? 'now' : i * 9 + 's' }}</span>
                            <span class="lf-dot" :class="'lf-dot-' + event.status" style="flex-shrink:0"></span>
                            <span class="lf-event-label">{{ event.label }}</span>
                        </div>
                        <div class="lf-empty" v-if="(flow?.events ?? []).length === 0">No recent flow events.</div>
                    </div>
                </div>

            </div>

            <!-- inspector -->
            <aside class="lf-inspector">
                <div class="lf-pane lf-pane-sticky">
                    <div class="lf-pane-head">
                        <span class="lf-pane-title">Inspector</span>
                        <span class="lf-status ms-auto" :class="'lf-status-' + selectedInspector.node.status">{{ statusLabel(selectedInspector.node.status) }}</span>
                    </div>

                    <div class="lf-insp-top">
                        <div class="lf-insp-name">{{ selectedInspector.node.label }}</div>
                        <div class="lf-insp-meta">
                            <span class="lf-insp-kind">{{ nodeKind(selectedInspector.node) }}</span>
                            <span class="lf-insp-conn">{{ selectedInspector.queue ? selectedInspector.queue.connection + ' · ' + selectedInspector.queue.name : selectedInspector.node.id }}</span>
                        </div>
                    </div>

                    <div class="lf-insp-sec">
                        <div class="lf-insp-sec-title">Metrics</div>
                        <div class="lf-kv" v-for="m in selectedInspector.metrics" :key="m[0]">
                            <span class="lf-kv-k">{{ m[0] }}</span>
                            <span class="lf-kv-v">{{ m[1] }}</span>
                        </div>
                    </div>

                    <div class="lf-insp-sec">
                        <div class="lf-insp-sec-title">Incoming</div>
                        <div class="lf-edge-row" v-for="edge in selectedInspector.incoming" :key="edge.id">
                            <span class="lf-dot" :class="'lf-dot-' + edge.status"></span>
                            <span class="lf-edge-lbl">{{ graphNodeLookup[edge.source]?.label ?? edge.source }}</span>
                            <span class="lf-edge-rate">{{ edgeDisplayLabel(edge) }}</span>
                        </div>
                        <div class="lf-empty-sm" v-if="selectedInspector.incoming.length === 0">—</div>
                    </div>

                    <div class="lf-insp-sec">
                        <div class="lf-insp-sec-title">Outgoing</div>
                        <div class="lf-edge-row" v-for="edge in selectedInspector.outgoing" :key="edge.id">
                            <span class="lf-dot" :class="'lf-dot-' + edge.status"></span>
                            <span class="lf-edge-lbl">{{ graphNodeLookup[edge.target]?.label ?? edge.target }}</span>
                            <span class="lf-edge-rate">{{ edgeDisplayLabel(edge) }}</span>
                        </div>
                        <div class="lf-empty-sm" v-if="selectedInspector.outgoing.length === 0">—</div>
                    </div>

                    <div class="lf-action" :class="'lf-action-' + selectedInspector.action.type">
                        <div class="lf-action-title">{{ selectedInspector.action.title }}</div>
                        <div class="lf-action-text">{{ selectedInspector.action.text }}</div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
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
    .lf-svg { width: 100%; height: auto; min-height: 280px; max-height: 660px; display: block; }
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
