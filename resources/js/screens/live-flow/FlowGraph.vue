<script type="text/ecmascript-6">
    import formatters from './formatters';
    import { loadViewState, saveViewState } from './viewState';

    export default {
        mixins: [formatters],

        props: {
            nodes: { type: Array, default: () => [] },
            edges: { type: Array, default: () => [] },
            flowCounts: { type: Object, default: () => ({ dispatched: 0, reserved: 0, completed: 0, failed: 0 }) },
            live: { type: Boolean, default: true },
            svgHeight: { type: Number, default: 620 },
            selectedId: { type: String, default: null },
            isDark: { type: Boolean, default: false },
            zoom: { type: Number, default: 1 },
        },

        emits: ['select', 'update:zoom'],

        data() {
            const saved = loadViewState();
            const offsets = Object.entries(saved.nodeOffsets ?? {}).filter(
                ([, off]) => Number.isFinite(off?.dx) && Number.isFinite(off?.dy)
            );

            return {
                panX: Number.isFinite(saved.panX) ? saved.panX : 0,
                panY: Number.isFinite(saved.panY) ? saved.panY : 0,
                isPanning: false,
                nodeOffsets: Object.fromEntries(offsets),
                draggingNodeId: null,
            };
        },

        computed: {
            effectiveNodes() {
                return this.nodes.map(n => {
                    const off = this.nodeOffsets[n.id];
                    return off ? { ...n, x: n.x + off.dx, y: n.y + off.dy } : n;
                });
            },

            effectiveNodeLookup() {
                return this.effectiveNodes.reduce((acc, n) => { acc[n.id] = n; return acc; }, {});
            },

            edgeGradients() {
                return this.edges.map(edge => {
                    const s = this.effectiveNodeLookup[edge.source];
                    const t = this.effectiveNodeLookup[edge.target];
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

            viewportTransform() { return `translate(${this.panX} ${this.panY}) scale(${this.zoom})`; },
            zoomLabel()         { return Math.round(this.zoom * 100) + '%'; },
            zoomMode()          { return this.zoom >= 1.35 ? 'individual' : 'class'; },
            hasCustomLayout()   { return Object.keys(this.nodeOffsets).length > 0; },
        },

        methods: {
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
                if (this.isPanning) saveViewState({ panX: this.panX, panY: this.panY });
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
                saveViewState({ panX: this.panX, panY: this.panY });
                this.$emit('update:zoom', +newZoom.toFixed(4));
            },

            zoomIn()    { this.$emit('update:zoom', Math.min(2.5, +(this.zoom * 1.2).toFixed(4))); },
            zoomOut()   { this.$emit('update:zoom', Math.max(0.35, +(this.zoom / 1.2).toFixed(4))); },
            resetView() {
                this.panX = 0;
                this.panY = 0;
                saveViewState({ panX: 0, panY: 0 });
                this.$emit('update:zoom', 1);
            },
            resetLayout() {
                this.nodeOffsets = {};
                saveViewState({ nodeOffsets: {} });
            },

            onNodePointerDown(e, node) {
                e.stopPropagation();
                const svgPos = this.getSVGCoords(e);
                this._dragState = {
                    id: node.id,
                    startSvg: svgPos,
                    startOffset: { ...(this.nodeOffsets[node.id] ?? { dx: 0, dy: 0 }) },
                    moved: false,
                };
                this.draggingNodeId = node.id;
                e.currentTarget.setPointerCapture(e.pointerId);
            },

            onNodePointerMove(e, node) {
                if (!this._dragState || this._dragState.id !== node.id) return;
                const curr = this.getSVGCoords(e);
                const dx = curr.x - this._dragState.startSvg.x;
                const dy = curr.y - this._dragState.startSvg.y;
                if (!this._dragState.moved && Math.hypot(dx, dy) < 5) return;
                this._dragState.moved = true;
                this.nodeOffsets = {
                    ...this.nodeOffsets,
                    [node.id]: {
                        dx: this._dragState.startOffset.dx + dx,
                        dy: this._dragState.startOffset.dy + dy,
                    },
                };
            },

            onNodePointerUp(e, node) {
                if (!this._dragState || this._dragState.id !== node.id) return;
                const wasDrag = this._dragState.moved;
                this._dragState = null;
                this.draggingNodeId = null;
                try { e.currentTarget.releasePointerCapture(e.pointerId); } catch {}
                if (wasDrag) saveViewState({ nodeOffsets: this.nodeOffsets });
                else this.$emit('select', node.id);
            },

            edgePath(edge) {
                const s = this.effectiveNodeLookup[edge.source];
                const t = this.effectiveNodeLookup[edge.target];
                if (!s || !t) return '';
                const sx = s.x + s.width, sy = s.y + s.height / 2;
                const tx = t.x, ty = t.y + t.height / 2;
                const bend = Math.max(45, Math.abs(tx - sx) * 0.45);
                return `M ${sx} ${sy} C ${sx + bend} ${sy} ${tx - bend} ${ty} ${tx} ${ty}`;
            },

            edgeLabelPos(edge) {
                const s = this.effectiveNodeLookup[edge.source];
                const t = this.effectiveNodeLookup[edge.target];
                return { x: s && t ? (s.x + s.width + t.x) / 2 : 0, y: s && t ? (s.y + t.y) / 2 + 10 : 0 };
            },

            edgeDisplayLabel(edge) {
                const rate = Number(edge.rate_per_minute ?? 0);
                if (rate <= 0) return ['dispatch', 'reserve', 'finish'].includes(edge.label) ? 'idle' : edge.label;
                return `${this.formatNumber(rate)}/m`;
            },

            edgeColor(status) {
                return { healthy: 'var(--lf-cyan)', warning: 'var(--lf-amber)', critical: 'var(--lf-red)' }[status] ?? 'var(--lf-cyan)';
            },

            nodeFill(node) {
                if (node.status === 'critical') return 'var(--lf-node-critical-bg)';
                if (node.status === 'warning')  return 'var(--lf-node-warning-bg)';
                return { producer: 'var(--lf-node-producer-bg)', queue: 'var(--lf-node-queue-bg)', job: 'var(--lf-node-queue-bg)', worker: 'var(--lf-node-worker-bg)', result: 'var(--lf-node-result-bg)' }[node.type] ?? 'var(--lf-node-queue-bg)';
            },

            nodeStroke(node) {
                if (node.id === this.selectedId) return 'var(--lf-violet)';
                if (node.status === 'critical')  return 'var(--lf-node-critical-stroke)';
                if (node.status === 'warning')   return 'var(--lf-node-warning-stroke)';
                return { producer: 'var(--lf-node-producer-stroke)', queue: 'var(--lf-node-queue-stroke)', job: 'var(--lf-cyan)', worker: 'var(--lf-node-worker-stroke)', result: 'var(--lf-node-result-stroke)' }[node.type] ?? 'var(--lf-node-queue-stroke)';
            },

            nodeStrokeWidth(node) { return node.id === this.selectedId ? 2.2 : 1.5; },

            nodeAccent(node) {
                if (node.status === 'critical') return 'var(--lf-red)';
                if (node.status === 'warning')  return 'var(--lf-amber)';
                return { producer: 'var(--lf-blue)', queue: 'var(--lf-violet)', job: 'var(--lf-cyan)', worker: 'var(--lf-green)', result: 'var(--lf-green)' }[node.type] ?? 'var(--lf-violet)';
            },

            nodeTextColor(node) {
                if (node.status === 'critical') return 'var(--lf-red)';
                if (node.status === 'warning')  return 'var(--lf-amber)';
                return 'var(--lf-svg-muted)';
            },

            nodeGradColor(node, edgeStatus) {
                if (edgeStatus === 'critical') return 'var(--lf-red)';
                if (edgeStatus === 'warning')  return 'var(--lf-amber)';
                return { producer: 'var(--lf-blue)', queue: 'var(--lf-violet)', job: 'var(--lf-cyan)', worker: 'var(--lf-green)', result: 'var(--lf-green)' }[node.type] ?? 'var(--lf-cyan)';
            },

        },
    };
</script>

<template>
    <div class="lf-pane">
        <div class="lf-pane-head">
            <span class="lf-pane-title">Graph</span>
            <span class="lf-pane-meta">{{ effectiveNodes.length }} nodes · {{ edges.length }} edges</span>
            <span class="lf-zoom-mode-badge" :class="'lf-zmb-' + zoomMode" :title="zoomMode === 'individual' ? 'Showing individual job instances (zoom ≥ 135%)' : 'Showing job classes (zoom in to see individual jobs)'">
                {{ zoomMode === 'individual' ? 'jobs' : 'classes' }}
            </span>
            <div class="lf-vp">
                <button v-if="hasCustomLayout" class="lf-vp-btn lf-vp-reset" @click="resetLayout" title="Reset node positions">
                    <svg width="9" height="9" viewBox="0 0 9 9" fill="none"><path d="M7.5 2A3.5 3.5 0 1 0 8 4.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M7.5 2V4H5.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
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
            <span class="lf-live-tag" :class="{ 'lf-live-tag-paused': !live }">{{ live ? 'live' : 'paused' }}</span>
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
                    <filter id="lf-drag-shadow" x="-25%" y="-25%" width="150%" height="150%">
                        <feDropShadow dx="0" dy="5" stdDeviation="7" flood-color="rgba(0,0,0,0.28)"/>
                    </filter>
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

                <g :transform="viewportTransform">

                    <path
                        v-for="edge in edges"
                        :key="'edge-' + edge.id"
                        :d="edgePath(edge)"
                        :stroke="'url(#lf-grad-' + svgId(edge.id) + ')'"
                        :stroke-width="edge.status === 'critical' ? 2 : 1.6"
                        stroke-linecap="round"
                        fill="none"
                        :opacity="edge.status === 'critical' ? 0.75 : 0.55"
                    />

                    <text
                        v-for="edge in edges"
                        :key="'el-' + edge.id"
                        :x="edgeLabelPos(edge).x"
                        :y="edgeLabelPos(edge).y"
                        class="lf-svg-mono"
                        font-size="7.5"
                        :fill="edgeColor(edge.status)"
                        :opacity="edge.status === 'critical' ? 0.9 : 0.65"
                    >{{ edgeDisplayLabel(edge) }}</text>

                    <g
                        v-for="node in effectiveNodes"
                        :key="node.id"
                        :class="['lf-svg-node', draggingNodeId === node.id ? 'lf-svg-node-dragging' : '']"
                        :filter="draggingNodeId === node.id ? 'url(#lf-drag-shadow)' : 'none'"
                        :style="{ cursor: draggingNodeId === node.id ? 'grabbing' : 'grab' }"
                        @pointerdown="onNodePointerDown($event, node)"
                        @pointermove="onNodePointerMove($event, node)"
                        @pointerup="onNodePointerUp($event, node)"
                        @pointerleave="onNodePointerUp($event, node)"
                    >
                        <rect
                            v-if="node.id === selectedId"
                            :x="node.x - 3" :y="node.y - 3"
                            :width="node.width + 6" :height="node.height + 6"
                            rx="6" fill="none"
                            stroke="var(--lf-violet)" stroke-width="1"
                            stroke-opacity="0.4"
                            :stroke-dasharray="'4 3'"
                        />
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
                        <rect v-if="node.id === selectedId" :x="node.x" :y="node.y" :width="node.width" :height="node.height" rx="3" fill="var(--lf-violet)" opacity="0.06"/>
                        <rect :x="node.x" :y="node.y + 2" width="3" :height="node.height - 4" rx="1.5" :fill="nodeAccent(node)" opacity="0.7"/>
                        <text :x="node.x + node.width / 2" :y="node.y + 18" text-anchor="middle" class="lf-svg-mono" font-size="8" :fill="nodeAccent(node)" letter-spacing="0.7">{{ nodeKind(node) }}</text>
                        <text :x="node.x + node.width / 2" :y="node.y + 31" text-anchor="middle" class="lf-svg-mono" font-size="9.5" fill="var(--lf-svg-text)">{{ node.label }}</text>
                        <text :x="node.x + node.width / 2" :y="node.y + 43" text-anchor="middle" class="lf-svg-mono" font-size="7.5" :fill="nodeTextColor(node)">{{ node.sub }}</text>
                    </g>
                </g>
            </svg>
        </div>

        <div class="lf-flowstats" v-if="flowCounts">
            <span class="lf-fs-heading">Flowed this session</span>
            <span class="lf-fs-pill lf-fs-dispatched" :title="`${flowCounts.dispatched} jobs dispatched`">
                <span class="lf-fs-num">{{ formatCount(flowCounts.dispatched) }}</span>
                <span class="lf-fs-lbl">dispatched</span>
            </span>
            <span class="lf-fs-pill lf-fs-reserved" :title="`${flowCounts.reserved} jobs reserved`">
                <span class="lf-fs-num">{{ formatCount(flowCounts.reserved) }}</span>
                <span class="lf-fs-lbl">reserved</span>
            </span>
            <span class="lf-fs-pill lf-fs-completed" :title="`${flowCounts.completed} jobs completed`">
                <span class="lf-fs-num">{{ formatCount(flowCounts.completed) }}</span>
                <span class="lf-fs-lbl">completed</span>
            </span>
            <span
                class="lf-fs-pill lf-fs-failed"
                :class="{ 'lf-fs-dim': flowCounts.failed === 0 }"
                :title="`${flowCounts.failed} jobs failed`"
            >
                <span class="lf-fs-num">{{ formatCount(flowCounts.failed) }}</span>
                <span class="lf-fs-lbl">failed</span>
            </span>
        </div>

        <div class="lf-legend">
            <span class="lf-leg-item lf-leg-producer">Producer</span>
            <span class="lf-leg-item lf-leg-queue">Queue</span>
            <span class="lf-leg-item lf-leg-job">Job</span>
            <span class="lf-leg-item lf-leg-worker">Worker</span>
            <span class="lf-leg-item lf-leg-done">Completed</span>
            <span class="lf-leg-item lf-leg-fail">Failed</span>
            <span class="lf-leg-sep"></span>
            <span class="lf-leg-item"><span class="lf-ll lf-ll-ok"></span>Healthy</span>
            <span class="lf-leg-item"><span class="lf-ll lf-ll-warn"></span>Backpressure</span>
            <span class="lf-leg-item"><span class="lf-ll lf-ll-crit"></span>Critical</span>
        </div>
    </div>
</template>
