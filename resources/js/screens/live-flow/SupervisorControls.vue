<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            supervisors: { type: Array, default: () => [] },
            controlling: { type: Array, default: () => [] },
        },

        emits: ['masters-action', 'supervisor-action'],

        methods: {
            isControlling(key) {
                return this.controlling.includes(key);
            },

            statusKey(status) {
                if (status === 'paused') return 'warning';
                if (status === 'inactive') return 'critical';
                return 'healthy';
            },

            processCount(processes) {
                return Object.values(processes ?? {}).reduce((sum, value) => sum + Number(value ?? 0), 0);
            },
        },
    };
</script>

<template>
    <div class="lf-pane lf-pane-gap">
        <div class="lf-pane-head">
            <span class="lf-pane-title">Horizon Controls</span>
            <span class="lf-pane-meta">{{ supervisors.length }} supervisor{{ supervisors.length === 1 ? '' : 's' }}</span>
            <div class="lf-head-actions">
                <button
                    class="lf-mini-btn lf-mini-btn-danger"
                    type="button"
                    :disabled="isControlling('masters:pause')"
                    @click="$emit('masters-action', 'pause')"
                >pause all</button>
                <button
                    class="lf-mini-btn lf-mini-btn-safe"
                    type="button"
                    :disabled="isControlling('masters:continue')"
                    @click="$emit('masters-action', 'continue')"
                >continue all</button>
            </div>
        </div>

        <div class="lf-supervisors" v-if="supervisors.length">
            <div class="lf-supervisor" v-for="supervisor in supervisors" :key="supervisor.name">
                <span class="lf-dot" :class="'lf-dot-' + statusKey(supervisor.status)"></span>
                <div class="lf-supervisor-main">
                    <div class="lf-supervisor-name">{{ supervisor.name }}</div>
                    <div class="lf-supervisor-sub">
                        {{ supervisor.options?.connection ?? 'connection' }} ·
                        {{ supervisor.options?.queue ?? 'queues' }} ·
                        {{ formatNumber(processCount(supervisor.processes)) }} procs
                    </div>
                </div>
                <span class="lf-status" :class="'lf-status-' + statusKey(supervisor.status)">{{ supervisor.status }}</span>
                <button
                    class="lf-mini-btn lf-mini-btn-danger"
                    type="button"
                    :disabled="isControlling(supervisor.name + ':pause') || supervisor.status === 'inactive'"
                    @click="$emit('supervisor-action', { supervisor, action: 'pause' })"
                >pause</button>
                <button
                    class="lf-mini-btn lf-mini-btn-safe"
                    type="button"
                    :disabled="isControlling(supervisor.name + ':continue') || supervisor.status === 'inactive'"
                    @click="$emit('supervisor-action', { supervisor, action: 'continue' })"
                >continue</button>
            </div>
        </div>

        <div class="lf-empty-sm" v-else>No Horizon supervisors detected.</div>
    </div>
</template>
