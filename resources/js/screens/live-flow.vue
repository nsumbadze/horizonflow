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
                jitterKey: 0,
            };
        },

        mounted() {
            document.title = "HorizonXBrain - Live Flow";
            this.refreshFlowPeriodically();

            this.jitterTimer = setInterval(() => {
                if (this.live) {
                    this.jitterKey++;
                }
            }, 1800);
        },

        beforeUnmount() {
            clearInterval(this.jitterTimer);
        },

        computed: {
            summary() {
                return this.flow?.summary ?? {};
            },

            generatedAt() {
                if (!this.flow?.generated_at) {
                    return 'Generated: -';
                }

                return 'Generated: ' + new Date(this.flow.generated_at).toLocaleTimeString();
            },

            sourceClass() {
                return {
                    mock: 'mock',
                    redis: 'redis',
                    database: 'db',
                }[this.flow?.source] ?? 'mock';
            },

            queues() {
                return this.flow?.queues ?? [];
            },

            filteredQueues() {
                const filter = this.filterText.trim().toLowerCase();

                if (!filter) {
                    return this.queues;
                }

                return this.queues.filter((queue) => {
                    return [queue.name, queue.connection, queue.driver]
                        .filter(Boolean)
                        .some((value) => String(value).toLowerCase().includes(filter));
                });
            },

            nodeLookup() {
                return (this.flow?.nodes ?? []).reduce((nodes, node) => {
                    nodes[node.id] = node;

                    return nodes;
                }, {});
            },

            graphNodes() {
                const queues = this.filteredQueues.map((queue, index) => {
                    const node = this.findQueueNode(queue);
                    const y = this.distributedY(index, this.filteredQueues.length, 82, 310);

                    return {
                        id: node?.id ?? this.queueNodeId(queue),
                        type: 'queue',
                        label: queue.name,
                        sub: `${queue.connection} · ${this.formatNumber(queue.pending)} pending`,
                        status: node?.status ?? this.queueStatus(queue),
                        x: 250,
                        y,
                        width: 128,
                        height: 50,
                        metrics: {
                            pending: queue.pending,
                            delayed: queue.delayed,
                            wait: queue.wait_seconds,
                            processes: queue.processes,
                            throughput: queue.throughput_per_minute,
                        },
                    };
                });

                const workers = (this.flow?.nodes ?? [])
                    .filter((node) => node.type === 'worker')
                    .slice(0, 4)
                    .map((node, index, all) => ({
                        id: node.id,
                        type: 'worker',
                        label: node.label,
                        sub: `${this.formatNumber(node.metrics?.processes ?? this.summary.processing)} processes`,
                        status: node.status,
                        x: 500,
                        y: this.distributedY(index, all.length || 1, 75, 285),
                        width: 128,
                        height: 46,
                        metrics: node.metrics ?? {},
                    }));

                const workerNodes = workers.length ? workers : [{
                    id: 'workers',
                    type: 'worker',
                    label: 'workers',
                    sub: `${this.formatNumber(this.summary.processing)} active`,
                    status: 'healthy',
                    x: 500,
                    y: 175,
                    width: 128,
                    height: 46,
                    metrics: { processes: this.summary.processing },
                }];

                const results = (this.flow?.nodes ?? [])
                    .filter((node) => node.type === 'result')
                    .slice(0, 4)
                    .map((node, index, all) => ({
                        id: node.id,
                        type: 'result',
                        label: node.label,
                        sub: this.resultSubLabel(node),
                        status: node.status,
                        x: 750,
                        y: this.distributedY(index, all.length || 1, 88, 310),
                        width: 132,
                        height: 50,
                        metrics: node.metrics ?? {},
                    }));

                return [
                    {
                        id: 'producer-app',
                        type: 'producer',
                        label: 'producer-app',
                        sub: `${this.formatNumber(this.summary.throughput_per_minute)} jobs/min`,
                        status: 'healthy',
                        x: 28,
                        y: 105,
                        width: 136,
                        height: 52,
                        metrics: { throughput: this.summary.throughput_per_minute },
                    },
                    {
                        id: 'producer-scheduler',
                        type: 'producer',
                        label: 'scheduler',
                        sub: `${this.formatNumber(this.summary.delayed)} delayed`,
                        status: this.summary.delayed > 0 ? 'warning' : 'healthy',
                        x: 28,
                        y: 235,
                        width: 136,
                        height: 52,
                        metrics: { delayed: this.summary.delayed },
                    },
                    ...queues,
                    ...workerNodes,
                    ...results,
                ];
            },

            graphNodeLookup() {
                return this.graphNodes.reduce((nodes, node) => {
                    nodes[node.id] = node;

                    return nodes;
                }, {});
            },

            graphEdges() {
                const existing = (this.flow?.edges ?? [])
                    .filter((edge) => this.graphNodeLookup[edge.source] && this.graphNodeLookup[edge.target])
                    .map((edge) => ({
                        ...edge,
                        rate_per_minute: edge.rate_per_minute,
                    }));

                if (existing.length) {
                    return existing;
                }

                const workers = this.graphNodes.filter((node) => node.type === 'worker');
                const results = this.graphNodes.filter((node) => node.type === 'result');
                const completed = results.find((node) => node.label === 'completed') ?? results[0];
                const failed = results.find((node) => node.label === 'failed');

                const generated = [];

                this.graphNodes.filter((node) => node.type === 'queue').forEach((queue, index) => {
                    const worker = workers[index % workers.length];
                    const producer = queue.status === 'critical' || queue.status === 'warning'
                        ? 'producer-scheduler'
                        : 'producer-app';

                    generated.push(this.edge(producer, queue.id, queue.status, 'dispatch', queue.metrics.throughput));
                    generated.push(this.edge(queue.id, worker.id, queue.status, 'reserve', queue.metrics.throughput));
                });

                if (completed) {
                    workers.forEach((worker) => generated.push(this.edge(worker.id, completed.id, 'healthy', 'finish', this.summary.throughput_per_minute)));
                }

                if (failed && Number(this.summary.failed ?? 0) > 0) {
                    generated.push(this.edge(workers[workers.length - 1].id, failed.id, 'critical', 'exception', this.summary.failed));
                }

                return generated;
            },

            particles() {
                return this.graphEdges.flatMap((edge, edgeIndex) => {
                    const count = edge.status === 'critical'
                        ? 2
                        : edge.status === 'warning'
                            ? 2
                            : Math.min(3, Math.max(1, Math.ceil((edge.rate_per_minute ?? 20) / 120)));

                    return Array.from({ length: count }).map((_, index) => ({
                        id: `${edge.id}-${index}`,
                        edgeId: this.svgId(edge.id),
                        status: edge.status,
                        delay: `${(index / count) * this.particleDuration(edge.status) + (edgeIndex % 3) * 0.15}s`,
                        duration: `${this.particleDuration(edge.status)}s`,
                    }));
                }).slice(0, 28);
            },

            selectedNode() {
                return this.graphNodeLookup[this.selectedId] ?? this.graphNodes.find((node) => node.type === 'queue') ?? this.graphNodes[0];
            },

            selectedInspector() {
                const node = this.selectedNode;
                const queue = this.queues.find((item) => this.queueNodeId(item) === node.id || this.findQueueNode(item)?.id === node.id);
                const incoming = this.graphEdges.filter((edge) => edge.target === node.id);
                const outgoing = this.graphEdges.filter((edge) => edge.source === node.id);

                return {
                    node,
                    queue,
                    metrics: this.inspectorMetrics(node, queue),
                    incoming,
                    outgoing,
                    action: this.suggestedAction(node, queue),
                };
            },
        },

        methods: {
            refreshFlowPeriodically() {
                this.refreshing = true;

                return this.$http.get(Horizon.basePath + '/api/flow')
                    .then(response => {
                        this.flow = response.data;
                        this.ready = true;

                        if (!this.selectedId || !this.graphNodeLookup[this.selectedId]) {
                            this.selectedId = this.graphNodes.find((node) => node.type === 'queue')?.id ?? this.graphNodes[0]?.id;
                        }
                    })
                    .finally(() => {
                        this.refreshing = false;
                    });
            },

            selectNode(id) {
                this.selectedId = id;
            },

            toggleLive() {
                this.live = !this.live;
            },

            svgId(value) {
                return String(value).replace(/[^a-z0-9_-]+/gi, '-');
            },

            metricValue(value, suffix = '') {
                if (value === null || value === undefined) {
                    return 'n/a';
                }

                return this.formatNumber(value) + suffix;
            },

            formatNumber(value) {
                if (value === null || value === undefined) {
                    return 'n/a';
                }

                if (typeof value === 'number' && !Number.isInteger(value)) {
                    return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
                }

                return Number(value).toLocaleString();
            },

            formatRate(value) {
                return value === null || value === undefined ? 'n/a' : `${this.formatNumber(value)}/m`;
            },

            statusLabel(status) {
                return {
                    healthy: 'healthy',
                    warning: 'backpressure',
                    critical: 'critical',
                }[status] ?? status;
            },

            edge(source, target, status, label, rate) {
                return {
                    id: `${source}-${target}`,
                    source,
                    target,
                    status,
                    label,
                    rate_per_minute: rate,
                };
            },

            edgePath(edge) {
                const source = this.graphNodeLookup[edge.source];
                const target = this.graphNodeLookup[edge.target];

                if (!source || !target) {
                    return '';
                }

                const sx = source.x + source.width;
                const sy = source.y + (source.height / 2);
                const tx = target.x;
                const ty = target.y + (target.height / 2);
                const bend = Math.max(45, Math.abs(tx - sx) * 0.45);

                return `M ${sx} ${sy} C ${sx + bend} ${sy} ${tx - bend} ${ty} ${tx} ${ty}`;
            },

            edgeLabelPosition(edge) {
                const source = this.graphNodeLookup[edge.source];
                const target = this.graphNodeLookup[edge.target];

                return {
                    x: source && target ? (source.x + source.width + target.x) / 2 : 0,
                    y: source && target ? (source.y + target.y) / 2 + 10 : 0,
                };
            },

            distributedY(index, total, min, max) {
                if (total <= 1) {
                    return (min + max) / 2;
                }

                return min + ((max - min) / (total - 1)) * index;
            },

            findQueueNode(queue) {
                return (this.flow?.nodes ?? []).find((node) => {
                    return node.type === 'queue' && (
                        node.label === queue.name ||
                        node.id === this.queueNodeId(queue) ||
                        node.id.endsWith(`-${queue.name}`)
                    );
                });
            },

            queueNodeId(queue) {
                return `queue-${queue.connection}-${queue.name}`.replace(/[^a-z0-9-]+/gi, '-').toLowerCase();
            },

            queueStatus(queue) {
                if (queue.wait_seconds >= 30 || queue.pending >= 500) {
                    return 'critical';
                }

                if (queue.wait_seconds >= 10 || queue.pending >= 100 || queue.delayed > 0) {
                    return 'warning';
                }

                return 'healthy';
            },

            resultSubLabel(node) {
                if (node.label === 'failed') {
                    return `${this.formatNumber(this.summary.failed)} failed`;
                }

                if (node.label === 'delayed') {
                    return `${this.formatNumber(this.summary.delayed)} delayed`;
                }

                return `${this.formatNumber(this.summary.completed)} completed`;
            },

            nodeFill(node) {
                if (node.type === 'producer') {
                    return '#0c1d30';
                }

                if (node.type === 'worker') {
                    return node.status === 'warning' ? '#1a1205' : '#091e17';
                }

                if (node.type === 'result') {
                    return node.status === 'critical' ? '#1e0a0c' : node.status === 'warning' ? '#1a1305' : '#091e12';
                }

                return node.status === 'critical' ? '#1e0a0c' : node.status === 'warning' ? '#1a1305' : '#0a1c2e';
            },

            nodeStroke(node) {
                return {
                    healthy: node.type === 'worker' || node.type === 'result' ? '#164a38' : '#1a4a6e',
                    warning: '#f0a030',
                    critical: '#e0404a',
                }[node.status] ?? '#1a4a6e';
            },

            nodeAccent(node) {
                return {
                    producer: '#4a90d9',
                    queue: node.status === 'critical' ? '#e0404a' : node.status === 'warning' ? '#f0a030' : '#00c8d4',
                    worker: node.status === 'warning' ? '#f0a030' : '#22c878',
                    result: node.status === 'critical' ? '#e0404a' : node.status === 'warning' ? '#f0a030' : '#22c878',
                }[node.type] ?? '#00c8d4';
            },

            nodeKind(node) {
                return {
                    producer: 'PRODUCER',
                    queue: 'QUEUE',
                    worker: 'WORKER',
                    result: node.label?.toUpperCase?.() ?? 'RESULT',
                }[node.type] ?? node.type?.toUpperCase?.();
            },

            edgeColor(status) {
                return {
                    healthy: '#00c8d4',
                    warning: '#f0a030',
                    critical: '#e0404a',
                }[status] ?? '#00c8d4';
            },

            particleDuration(status) {
                return {
                    healthy: 1.7,
                    warning: 2.6,
                    critical: 3.2,
                }[status] ?? 2;
            },

            inspectorMetrics(node, queue) {
                if (queue) {
                    return [
                        ['Connection', queue.connection],
                        ['Driver', queue.driver],
                        ['Pending', this.formatNumber(queue.pending)],
                        ['Delayed', this.formatNumber(queue.delayed)],
                        ['Wait', this.metricValue(queue.wait_seconds, 's')],
                        ['Processes', this.formatNumber(queue.processes)],
                        ['Throughput', this.formatRate(queue.throughput_per_minute)],
                    ];
                }

                return Object.entries(node.metrics ?? {}).map(([key, value]) => [
                    key.replace(/_/g, ' '),
                    this.formatNumber(value),
                ]);
            },

            suggestedAction(node, queue) {
                const status = node.status;

                if (status === 'critical') {
                    return {
                        type: 'critical',
                        title: 'Immediate Action',
                        text: queue
                            ? `Backlog is critical on ${queue.name}. Scale workers or reduce dispatch rate. Example: php artisan horizon:supervisor ${queue.name}`
                            : 'Failures are above normal. Inspect the failed job payloads and retry only after the root cause is fixed.',
                    };
                }

                if (status === 'warning') {
                    return {
                        type: 'warn',
                        title: 'Suggested Action',
                        text: queue
                            ? `${queue.name} is showing backpressure. Watch wait time and consider increasing process capacity if it keeps rising.`
                            : 'This node is under pressure. Monitor incoming rates and downstream failures.',
                    };
                }

                return {
                    type: 'ok',
                    title: 'Status',
                    text: 'Node is operating normally. No action required.',
                };
            },
        },
    }
</script>

<template>
    <div class="hxb-live-flow">
        <poll @poll="refreshFlowPeriodically" :interval="5" />

        <header class="hxb-header">
            <div class="hxb-brand">
                <svg class="hxb-logo" viewBox="0 0 26 26" fill="none">
                    <circle cx="13" cy="13" r="11" stroke="#00c8d4" stroke-width="1.4" opacity="0.55"/>
                    <circle cx="13" cy="13" r="5.5" stroke="#00c8d4" stroke-width="1.4"/>
                    <circle cx="13" cy="13" r="2" fill="#00c8d4"/>
                    <line x1="2" y1="13" x2="7.5" y2="13" stroke="#00c8d4" stroke-width="1.4" opacity="0.55"/>
                    <line x1="18.5" y1="13" x2="24" y2="13" stroke="#00c8d4" stroke-width="1.4" opacity="0.55"/>
                    <line x1="13" y1="2" x2="13" y2="7.5" stroke="#00c8d4" stroke-width="1.4" opacity="0.55"/>
                    <line x1="13" y1="18.5" x2="13" y2="24" stroke="#00c8d4" stroke-width="1.4" opacity="0.55"/>
                </svg>
                <span class="hxb-brand-name">HorizonX<em>Brain</em><span> · </span><strong>Live Flow</strong></span>
            </div>

            <span class="hxb-source-badge" :class="'hxb-source-' + sourceClass">
                <span class="hxb-pulse"></span>
                {{ flow?.source ?? 'loading' }}
            </span>

            <span class="hxb-ts">{{ generatedAt }}</span>

            <div class="hxb-spacer"></div>

            <div class="hxb-controls">
                <input v-model="filterText" class="hxb-control" type="text" placeholder="Filter queues..." />
                <select v-model="timeRange" class="hxb-control">
                    <option>Last 5m</option>
                    <option>Last 15m</option>
                    <option>Last 1h</option>
                    <option>Last 6h</option>
                    <option>Last 24h</option>
                </select>
                <button class="hxb-button" type="button" @click="refreshFlowPeriodically">
                    <svg :class="{ spinning: refreshing }" width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M10.5 2A5 5 0 1 0 11 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M10.5 2V5H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Refresh
                </button>
                <button class="hxb-button" :class="{ active: live }" type="button" @click="toggleLive">
                    <span class="hxb-pulse hxb-pulse-green"></span>
                    Live
                </button>
            </div>
        </header>

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

        <div class="hxb-main">
            <div class="hxb-left">
                <section class="hxb-panel">
                    <div class="hxb-panel-head">
                        <span class="hxb-panel-title">Flow Graph</span>
                        <span class="hxb-panel-sub">{{ graphNodes.length }} nodes · {{ graphEdges.length }} edges</span>
                        <span class="hxb-panel-badge">live</span>
                    </div>

                    <div class="hxb-canvas-wrap">
                        <svg class="hxb-flow-svg" viewBox="0 0 980 390" xmlns="http://www.w3.org/2000/svg">
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
                                    <stop offset="0%" stop-color="#e0404a" stop-opacity="0.18"/>
                                    <stop offset="100%" stop-color="#e0404a" stop-opacity="0"/>
                                </radialGradient>
                                <radialGradient id="hxb-warning" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="#f0a030" stop-opacity="0.14"/>
                                    <stop offset="100%" stop-color="#f0a030" stop-opacity="0"/>
                                </radialGradient>
                            </defs>

                            <g opacity="0.65">
                                <line x1="230" y1="0" x2="230" y2="390" stroke="rgba(0,200,212,0.05)" />
                                <line x1="490" y1="0" x2="490" y2="390" stroke="rgba(0,200,212,0.05)" />
                                <line x1="740" y1="0" x2="740" y2="390" stroke="rgba(0,200,212,0.05)" />
                                <line x1="0" y1="97" x2="980" y2="97" stroke="rgba(0,200,212,0.04)" />
                                <line x1="0" y1="195" x2="980" y2="195" stroke="rgba(0,200,212,0.04)" />
                                <line x1="0" y1="293" x2="980" y2="293" stroke="rgba(0,200,212,0.04)" />
                            </g>

                            <text x="96" y="17" text-anchor="middle" class="hxb-svg-text" font-size="8.5" fill="rgba(107,126,150,0.45)" letter-spacing="1.5">PRODUCERS</text>
                            <text x="314" y="17" text-anchor="middle" class="hxb-svg-text" font-size="8.5" fill="rgba(107,126,150,0.45)" letter-spacing="1.5">QUEUES</text>
                            <text x="564" y="17" text-anchor="middle" class="hxb-svg-text" font-size="8.5" fill="rgba(107,126,150,0.45)" letter-spacing="1.5">WORKERS</text>
                            <text x="816" y="17" text-anchor="middle" class="hxb-svg-text" font-size="8.5" fill="rgba(107,126,150,0.45)" letter-spacing="1.5">RESULTS</text>

                            <circle
                                v-for="node in graphNodes.filter((item) => item.status !== 'healthy')"
                                :key="'halo-' + node.id"
                                :cx="node.x + node.width / 2"
                                :cy="node.y + node.height / 2"
                                :r="node.status === 'critical' ? 54 : 46"
                                :fill="node.status === 'critical' ? 'url(#hxb-congestion)' : 'url(#hxb-warning)'"
                            >
                                <animate attributeName="r" :values="node.status === 'critical' ? '48;64;48' : '40;54;40'" :dur="node.status === 'critical' ? '3s' : '4s'" repeatCount="indefinite"/>
                                <animate attributeName="opacity" values="0.9;0.35;0.9" :dur="node.status === 'critical' ? '3s' : '4s'" repeatCount="indefinite"/>
                            </circle>

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

                            <text
                                v-for="edge in graphEdges"
                                :key="'edge-label-' + edge.id"
                                :x="edgeLabelPosition(edge).x"
                                :y="edgeLabelPosition(edge).y"
                                class="hxb-svg-text"
                                font-size="8"
                                :fill="edgeColor(edge.status)"
                                :opacity="edge.status === 'critical' ? 0.85 : 0.65"
                            >
                                {{ edge.status === 'critical' ? 'fail!' : formatRate(edge.rate_per_minute) }}
                            </text>

                            <g
                                v-for="node in graphNodes"
                                :key="node.id"
                                class="hxb-svg-node"
                                @click="selectNode(node.id)"
                            >
                                <rect
                                    :x="node.x"
                                    :y="node.y"
                                    :width="node.width"
                                    :height="node.height"
                                    rx="4"
                                    :fill="nodeFill(node)"
                                    :stroke="nodeStroke(node)"
                                    stroke-width="1.5"
                                >
                                    <animate v-if="node.status === 'critical'" attributeName="stroke-opacity" values="1;0.4;1" dur="2s" repeatCount="indefinite"/>
                                </rect>
                                <rect
                                    :x="node.x"
                                    :y="node.y"
                                    width="4"
                                    :height="node.height"
                                    rx="2"
                                    :fill="nodeAccent(node)"
                                    opacity="0.65"
                                />
                                <text :x="node.x + node.width / 2" :y="node.y + 19" text-anchor="middle" class="hxb-svg-text" font-size="8.5" :fill="nodeAccent(node)" letter-spacing="0.5">{{ nodeKind(node) }}</text>
                                <text :x="node.x + node.width / 2" :y="node.y + 32" text-anchor="middle" class="hxb-svg-text" font-size="9.5" fill="#c8d6e8">{{ node.label }}</text>
                                <text :x="node.x + node.width / 2" :y="node.y + 44" text-anchor="middle" class="hxb-svg-text" font-size="8" :fill="node.status === 'critical' ? '#e0404a' : node.status === 'warning' ? '#f0a030' : '#6b7e96'">{{ node.sub }}</text>
                            </g>

                            <circle
                                v-for="particle in particles"
                                :key="particle.id"
                                :r="particle.status === 'critical' ? 3 : 2.5"
                                :fill="edgeColor(particle.status)"
                                :filter="particle.status === 'critical' ? 'url(#hxb-f-red)' : particle.status === 'warning' ? 'url(#hxb-f-amber)' : 'url(#hxb-f-cyan)'"
                            >
                                <animateMotion :dur="particle.duration" :begin="particle.delay" repeatCount="indefinite" calcMode="linear">
                                    <mpath :href="'#hxb-path-' + particle.edgeId"></mpath>
                                </animateMotion>
                            </circle>
                        </svg>
                    </div>

                    <div class="hxb-legend">
                        <div class="hxb-legend-item"><span class="hxb-legend-dot producer"></span>Producer</div>
                        <div class="hxb-legend-item"><span class="hxb-legend-dot queue"></span>Queue</div>
                        <div class="hxb-legend-item"><span class="hxb-legend-dot worker"></span>Worker</div>
                        <div class="hxb-legend-item"><span class="hxb-legend-dot completed"></span>Completed</div>
                        <div class="hxb-legend-item"><span class="hxb-legend-dot failed"></span>Failed</div>
                        <span class="hxb-legend-sep"></span>
                        <div class="hxb-legend-item"><span class="hxb-legend-line healthy"></span>Healthy flow</div>
                        <div class="hxb-legend-item"><span class="hxb-legend-line warning"></span>Backpressure</div>
                        <div class="hxb-legend-item"><span class="hxb-legend-line critical"></span>Critical / Failed</div>
                    </div>
                </section>

                <section class="hxb-panel">
                    <div class="hxb-panel-head">
                        <span class="hxb-panel-title">Queues</span>
                        <span class="hxb-panel-sub">{{ filteredQueues.length }} queue{{ filteredQueues.length === 1 ? '' : 's' }}</span>
                    </div>
                    <div class="hxb-table-wrap">
                        <table class="hxb-table">
                            <thead>
                                <tr>
                                    <th>Queue</th>
                                    <th>Connection</th>
                                    <th>Driver</th>
                                    <th class="r">Pending</th>
                                    <th class="r">Delayed</th>
                                    <th class="r">Wait</th>
                                    <th class="r">Procs</th>
                                    <th class="r">Throughput</th>
                                    <th class="r">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="queue in filteredQueues"
                                    :key="queue.connection + ':' + queue.name"
                                    :class="{ selected: selectedId === (findQueueNode(queue)?.id ?? queueNodeId(queue)) }"
                                    @click="selectNode(findQueueNode(queue)?.id ?? queueNodeId(queue))"
                                >
                                    <td><span class="hxb-qname"><span class="hxb-status-dot" :class="'s-' + queueStatus(queue)"></span>{{ queue.name }}</span></td>
                                    <td class="dim">{{ queue.connection }}</td>
                                    <td><span class="hxb-driver" :class="'driver-' + queue.driver">{{ queue.driver }}</span></td>
                                    <td class="r" :class="{ warn: queue.pending > 100, danger: queue.pending > 500 }">{{ formatNumber(queue.pending) }}</td>
                                    <td class="r dim">{{ formatNumber(queue.delayed) }}</td>
                                    <td class="r" :class="{ warn: queue.wait_seconds >= 10, danger: queue.wait_seconds >= 30 }">{{ metricValue(queue.wait_seconds, 's') }}</td>
                                    <td class="r">{{ formatNumber(queue.processes) }}</td>
                                    <td class="r ok">{{ formatRate(queue.throughput_per_minute) }}</td>
                                    <td class="r"><span class="hxb-status-badge" :class="'b-' + queueStatus(queue)">{{ statusLabel(queueStatus(queue)) }}</span></td>
                                </tr>
                                <tr v-if="ready && filteredQueues.length === 0">
                                    <td colspan="9" class="dim">No queues match the current filter.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="hxb-panel">
                    <div class="hxb-panel-head">
                        <span class="hxb-panel-title">Activity</span>
                        <span class="hxb-panel-badge">recent events</span>
                    </div>
                    <div class="hxb-activity-scroll">
                        <div class="hxb-activity-item" v-for="(event, index) in flow?.events ?? []" :key="event.label">
                            <span class="hxb-activity-indicator" :class="'s-' + event.status"></span>
                            <div>
                                <div class="hxb-activity-label">{{ event.label }}</div>
                                <div class="hxb-activity-meta">{{ index === 0 ? 'now' : index * 9 + 's ago' }}</div>
                            </div>
                        </div>
                        <div class="hxb-activity-item dim" v-if="ready && (flow?.events ?? []).length === 0">No recent flow events.</div>
                    </div>
                </section>
            </div>

            <aside class="hxb-inspector">
                <section class="hxb-panel">
                    <div class="hxb-panel-head">
                        <span class="hxb-panel-title">Inspector</span>
                        <span class="hxb-status-badge" :class="'b-' + selectedInspector.node.status">{{ statusLabel(selectedInspector.node.status) }}</span>
                    </div>

                    <div class="hxb-inspector-top">
                        <div class="hxb-inspector-name">{{ selectedInspector.node.label }}</div>
                        <div class="hxb-inspector-type">
                            <span>{{ nodeKind(selectedInspector.node) }}</span>
                            <span class="hxb-connection-tag">{{ selectedInspector.queue ? selectedInspector.queue.connection + ' · ' + selectedInspector.queue.name : selectedInspector.node.id }}</span>
                        </div>
                    </div>

                    <div class="hxb-inspector-section">
                        <div class="hxb-inspector-title">Metrics</div>
                        <div class="hxb-metric-row" v-for="metric in selectedInspector.metrics" :key="metric[0]">
                            <span>{{ metric[0] }}</span>
                            <strong>{{ metric[1] }}</strong>
                        </div>
                    </div>

                    <div class="hxb-inspector-section">
                        <div class="hxb-inspector-title">Incoming</div>
                        <div class="hxb-edge-row" v-for="edge in selectedInspector.incoming" :key="edge.id">
                            <span class="hxb-status-dot" :class="'s-' + edge.status"></span>
                            <span>{{ graphNodeLookup[edge.source]?.label ?? edge.source }}</span>
                            <small>{{ formatRate(edge.rate_per_minute) }}</small>
                        </div>
                        <div class="hxb-empty" v-if="selectedInspector.incoming.length === 0">-</div>
                    </div>

                    <div class="hxb-inspector-section">
                        <div class="hxb-inspector-title">Outgoing</div>
                        <div class="hxb-edge-row" v-for="edge in selectedInspector.outgoing" :key="edge.id">
                            <span class="hxb-status-dot" :class="'s-' + edge.status"></span>
                            <span>{{ graphNodeLookup[edge.target]?.label ?? edge.target }}</span>
                            <small>{{ formatRate(edge.rate_per_minute) }}</small>
                        </div>
                        <div class="hxb-empty" v-if="selectedInspector.outgoing.length === 0">-</div>
                    </div>

                    <div class="hxb-action-block" :class="'action-' + selectedInspector.action.type">
                        <div class="hxb-action-title">{{ selectedInspector.action.title }}</div>
                        <div class="hxb-action-text">{{ selectedInspector.action.text }}</div>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</template>

<style scoped>
    .hxb-live-flow {
        --bg-base: #0b0e14;
        --bg-panel: #111520;
        --bg-card: #161c2a;
        --bg-hover: #1a2235;
        --border: #1e2a3a;
        --border-bright: #243044;
        --text-primary: #c8d6e8;
        --text-secondary: #6b7e96;
        --text-dim: #3d4f66;
        --cyan: #00c8d4;
        --cyan-dim: rgba(0, 200, 212, .12);
        --cyan-border: rgba(0, 200, 212, .25);
        --amber: #f0a030;
        --amber-dim: rgba(240, 160, 48, .12);
        --amber-border: rgba(240, 160, 48, .25);
        --red: #e0404a;
        --red-dim: rgba(224, 64, 74, .12);
        --red-border: rgba(224, 64, 74, .25);
        --green: #22c878;
        --green-dim: rgba(34, 200, 120, .12);
        --blue: #4a90d9;
        --blue-dim: rgba(74, 144, 217, .12);
        position: relative;
        z-index: 0;
        margin: -1.5rem -1rem 0;
        padding: 0 1rem 2rem;
        min-height: calc(100vh - 8rem);
        background: var(--bg-base);
        color: var(--text-primary);
        font-family: ui-monospace, "Cascadia Code", "Fira Code", "SF Mono", Consolas, monospace;
        font-size: 12px;
        line-height: 1.5;
    }

    .hxb-live-flow::before {
        content: "";
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(0, 200, 212, .025) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0, 200, 212, .025) 1px, transparent 1px);
        background-size: 48px 48px;
        pointer-events: none;
        z-index: -1;
    }

    .hxb-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
        flex-wrap: wrap;
    }

    .hxb-brand,
    .hxb-controls,
    .hxb-button,
    .hxb-source-badge,
    .hxb-qname,
    .hxb-legend-item,
    .hxb-edge-row,
    .hxb-inspector-type {
        display: flex;
        align-items: center;
    }

    .hxb-brand { gap: 9px; }
    .hxb-logo { width: 26px; height: 26px; flex-shrink: 0; }
    .hxb-brand-name { font-size: 14px; font-weight: 700; letter-spacing: .04em; white-space: nowrap; }
    .hxb-brand-name em { color: var(--cyan); font-style: normal; }
    .hxb-brand-name span, .hxb-brand-name strong { color: var(--text-secondary); font-weight: 400; }
    .hxb-spacer { flex: 1; }

    .hxb-source-badge {
        gap: 5px;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .1em;
        text-transform: uppercase;
    }

    .hxb-source-mock { background: var(--blue-dim); border: 1px solid rgba(74, 144, 217, .3); color: var(--blue); }
    .hxb-source-redis { background: var(--red-dim); border: 1px solid var(--red-border); color: var(--red); }
    .hxb-source-db { background: var(--green-dim); border: 1px solid rgba(34, 200, 120, .3); color: var(--green); }

    .hxb-pulse {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
        animation: hxb-blink 2s ease-in-out infinite;
    }

    .hxb-pulse-green { background: var(--green); }
    .hxb-ts { color: var(--text-secondary); font-size: 11px; white-space: nowrap; }
    .hxb-controls { gap: 6px; flex-wrap: wrap; }

    .hxb-control,
    .hxb-button {
        height: 27px;
        border-radius: 4px;
        border: 1px solid var(--border);
        background: var(--bg-card);
        color: var(--text-primary);
        font-family: inherit;
        font-size: 11px;
        outline: none;
    }

    .hxb-control { padding: 4px 9px; }
    .hxb-control[type="text"] { width: 170px; }
    .hxb-control::placeholder { color: var(--text-dim); }
    .hxb-button { gap: 5px; padding: 4px 9px; color: var(--text-secondary); cursor: pointer; transition: all .15s; }
    .hxb-button:hover, .hxb-button.active { border-color: var(--cyan); color: var(--cyan); background: var(--cyan-dim); }

    .hxb-kpi-strip {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 7px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
    }

    .hxb-kpi {
        background: var(--bg-panel);
        border: 1px solid var(--border);
        border-radius: 4px;
        padding: 9px 12px 8px;
    }

    .hxb-kpi-label {
        font-size: 9.5px;
        font-weight: 600;
        letter-spacing: .09em;
        text-transform: uppercase;
        color: var(--text-secondary);
        margin-bottom: 5px;
    }

    .hxb-kpi-value {
        font-size: 21px;
        font-weight: 700;
        letter-spacing: 0;
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    .hxb-kpi-sub { font-size: 9.5px; color: var(--text-dim); margin-top: 3px; }
    .hxb-kpi.pending .hxb-kpi-value { color: var(--cyan); }
    .hxb-kpi.processing .hxb-kpi-value { color: var(--blue); }
    .hxb-kpi.delayed .hxb-kpi-value { color: var(--amber); }
    .hxb-kpi.failed .hxb-kpi-value { color: var(--red); }
    .hxb-kpi.throughput .hxb-kpi-value { color: var(--green); }

    .hxb-main {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 340px;
        gap: 10px;
        margin-top: 10px;
    }

    .hxb-left { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
    .hxb-panel { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 6px; overflow: hidden; }

    .hxb-panel-head {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 9px 13px;
        border-bottom: 1px solid var(--border);
        background: var(--bg-card);
    }

    .hxb-panel-title { font-size: 10.5px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--text-secondary); }
    .hxb-panel-sub { font-size: 10px; color: var(--text-dim); }
    .hxb-panel-badge { margin-left: auto; padding: 2px 6px; border-radius: 3px; font-size: 9.5px; background: var(--cyan-dim); color: var(--cyan); border: 1px solid var(--cyan-border); font-weight: 600; letter-spacing: .05em; }

    .hxb-canvas-wrap { background: #090c12; position: relative; overflow-x: auto; }
    .hxb-flow-svg { width: 100%; min-width: 780px; display: block; }
    .hxb-svg-text { font-family: ui-monospace, "Cascadia Code", "SF Mono", monospace; }
    .hxb-svg-node { cursor: pointer; }
    .hxb-svg-node:hover rect:first-child { stroke-width: 2.2; }

    .hxb-legend {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 7px 13px;
        border-top: 1px solid var(--border);
        background: var(--bg-card);
        flex-wrap: wrap;
    }

    .hxb-legend-item { gap: 5px; font-size: 10px; color: var(--text-secondary); }
    .hxb-legend-dot { width: 8px; height: 8px; border-radius: 50%; }
    .hxb-legend-dot.producer { background: #1e6090; }
    .hxb-legend-dot.queue { background: #1a4a6e; }
    .hxb-legend-dot.worker { background: #164a38; }
    .hxb-legend-dot.completed { background: var(--green); }
    .hxb-legend-dot.failed { background: var(--red); }
    .hxb-legend-line { width: 18px; height: 2px; border-radius: 1px; }
    .hxb-legend-line.healthy { background: var(--cyan); }
    .hxb-legend-line.warning { background: var(--amber); }
    .hxb-legend-line.critical { background: var(--red); }
    .hxb-legend-sep { width: 1px; height: 12px; background: var(--border); }

    .hxb-table-wrap { overflow-x: auto; }
    .hxb-table { width: 100%; border-collapse: collapse; }
    .hxb-table th, .hxb-table td { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .hxb-table th { padding: 7px 11px; text-align: left; font-size: 9.5px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--text-dim); border-bottom: 1px solid var(--border); background: var(--bg-card); }
    .hxb-table td { padding: 6px 11px; font-size: 11px; color: var(--text-primary); border-bottom: 1px solid var(--border); }
    .hxb-table .r { text-align: right; }
    .hxb-table tbody tr { cursor: pointer; transition: background .1s; }
    .hxb-table tbody tr:hover { background: var(--bg-hover); }
    .hxb-table tbody tr.selected { background: rgba(0, 200, 212, .06); }
    .hxb-qname { gap: 6px; }

    .hxb-driver { padding: 1px 5px; border-radius: 3px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
    .driver-redis { background: var(--red-dim); color: var(--red); }
    .driver-mysql, .driver-database { background: var(--blue-dim); color: var(--blue); }
    .driver-pgsql { background: var(--green-dim); color: var(--green); }

    .hxb-status-dot, .hxb-activity-indicator { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .s-healthy { background: var(--green); }
    .s-warning { background: var(--amber); }
    .s-critical { background: var(--red); animation: hxb-blink 1s ease-in-out infinite; }
    .dim { color: var(--text-secondary) !important; }
    .warn { color: var(--amber) !important; }
    .danger { color: var(--red) !important; }
    .ok { color: var(--green) !important; }

    .hxb-status-badge { padding: 2px 7px; border-radius: 3px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
    .b-healthy { background: var(--green-dim); color: var(--green); }
    .b-warning { background: var(--amber-dim); color: var(--amber); }
    .b-critical { background: var(--red-dim); color: var(--red); }

    .hxb-activity-scroll { max-height: 210px; overflow-y: auto; }
    .hxb-activity-item { display: flex; align-items: flex-start; gap: 9px; padding: 6px 13px; border-bottom: 1px solid var(--border); transition: background .1s; }
    .hxb-activity-item:hover { background: var(--bg-hover); }
    .hxb-activity-indicator { margin-top: 4px; }
    .hxb-activity-label { font-size: 11px; color: var(--text-primary); line-height: 1.4; }
    .hxb-activity-meta { font-size: 10px; color: var(--text-dim); margin-top: 1px; }

    .hxb-inspector { position: sticky; top: 10px; max-height: calc(100vh - 20px); overflow-y: auto; align-self: start; }
    .hxb-inspector .hxb-panel-head .hxb-status-badge { margin-left: auto; }
    .hxb-inspector-top { padding: 11px 13px; }
    .hxb-inspector-name { font-size: 13px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
    .hxb-inspector-type { gap: 6px; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: var(--text-secondary); }
    .hxb-connection-tag { padding: 2px 6px; border-radius: 3px; font-size: 10px; background: var(--bg-hover); border: 1px solid var(--border); color: var(--text-secondary); text-transform: none; letter-spacing: 0; }
    .hxb-inspector-section { padding: 9px 13px; border-top: 1px solid var(--border); }
    .hxb-inspector-title { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--text-dim); margin-bottom: 7px; }
    .hxb-metric-row { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; padding: 2.5px 0; }
    .hxb-metric-row span { color: var(--text-secondary); font-size: 11px; text-transform: capitalize; }
    .hxb-metric-row strong { color: var(--text-primary); font-size: 11px; font-weight: 500; font-variant-numeric: tabular-nums; text-align: right; }
    .hxb-edge-row { gap: 6px; padding: 3.5px 0; font-size: 11px; }
    .hxb-edge-row span:nth-child(2) { flex: 1; color: var(--text-primary); }
    .hxb-edge-row small, .hxb-empty { color: var(--text-dim); font-size: 10px; }
    .hxb-action-block { margin: 0 13px 13px; padding: 9px 11px; border-radius: 4px; border-left: 3px solid var(--cyan); background: var(--cyan-dim); border: 1px solid var(--cyan-border); border-left-width: 3px; }
    .hxb-action-block.action-warn { background: var(--amber-dim); border-color: var(--amber-border); border-left-color: var(--amber); }
    .hxb-action-block.action-critical { background: var(--red-dim); border-color: var(--red-border); border-left-color: var(--red); }
    .hxb-action-title { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 5px; color: var(--cyan); }
    .action-warn .hxb-action-title { color: var(--amber); }
    .action-critical .hxb-action-title { color: var(--red); }
    .hxb-action-text { font-size: 11px; color: var(--text-secondary); line-height: 1.6; }

    @keyframes hxb-blink {
        0%, 100% { opacity: 1; }
        50% { opacity: .25; }
    }

    @keyframes hxb-spin {
        to { transform: rotate(360deg); }
    }

    .spinning { animation: hxb-spin .9s linear infinite; }

    @media (max-width: 1120px) {
        .hxb-main { grid-template-columns: 1fr; }
        .hxb-inspector { position: static; max-height: none; }
    }

    @media (max-width: 860px) {
        .hxb-kpi-strip { grid-template-columns: repeat(3, 1fr); }
    }

    @media (max-width: 520px) {
        .hxb-kpi-strip { grid-template-columns: repeat(2, 1fr); }
        .hxb-control[type="text"] { width: 100%; }
    }
</style>
