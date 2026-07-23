<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            supervisors: { type: Array, default: () => [] },
            controlling: { type: Array, default: () => [] },
        },

        emits: ['masters-action', 'supervisor-action'],

        data() {
            return {
                selectedSupervisorName: '',
            };
        },

        watch: {
            supervisors() {
                if (this.selectedSupervisorName && !this.selectedSupervisor) {
                    this.selectedSupervisorName = '';
                }
            },
        },

        computed: {
            supervisorSummary() {
                return this.supervisors.reduce((summary, supervisor) => {
                    const key = supervisor.status === 'paused' ? 'paused' : (supervisor.status === 'inactive' ? 'inactive' : 'running');
                    summary[key]++;
                    summary.processes += this.processCount(supervisor.processes);
                    return summary;
                }, { running: 0, paused: 0, inactive: 0, processes: 0 });
            },

            selectedSupervisor() {
                return this.supervisors.find(supervisor => supervisor.name === this.selectedSupervisorName) ?? null;
            },

            visibleSupervisors() {
                return this.selectedSupervisor ? [this.selectedSupervisor] : this.supervisors;
            },

            targetSummary() {
                return this.visibleSupervisors.reduce((summary, supervisor) => {
                    const key = supervisor.status === 'paused' ? 'paused' : (supervisor.status === 'inactive' ? 'inactive' : 'running');
                    summary[key]++;
                    summary.processes += this.processCount(supervisor.processes);
                    return summary;
                }, { running: 0, paused: 0, inactive: 0, processes: 0 });
            },
        },

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

            queueLabel(queue) {
                if (Array.isArray(queue)) return queue.join(', ');
                return queue || 'all queues';
            },

            requestSelectedAction(action) {
                if (this.selectedSupervisor) {
                    this.$emit('supervisor-action', { supervisor: this.selectedSupervisor, action });
                    return;
                }

                this.$emit('masters-action', action);
            },
        },
    };
</script>

<template>
    <div class="lf-pane lf-pane-gap">
        <div class="lf-pane-head">
            <span class="lf-pane-title">Horizon Controls</span>
            <span class="lf-pane-meta">{{ selectedSupervisor ? 'Selected supervisor' : `${supervisors.length} supervisor${supervisors.length === 1 ? '' : 's'}` }}</span>
            <label class="lf-control-picker">
                <span>Target</span>
                <select v-model="selectedSupervisorName">
                    <option value="">All supervisors</option>
                    <option v-for="supervisor in supervisors" :key="supervisor.name" :value="supervisor.name">
                        {{ supervisor.name }} · {{ supervisor.status }}
                    </option>
                </select>
            </label>
            <div class="lf-head-actions">
                <button
                    class="lf-control-btn lf-control-btn-warning"
                    type="button"
                    :disabled="selectedSupervisor
                        ? isControlling(selectedSupervisor.name + ':pause') || selectedSupervisor.status === 'inactive' || selectedSupervisor.status === 'paused'
                        : isControlling('masters:pause') || supervisorSummary.running === 0"
                    @click="requestSelectedAction('pause')"
                >{{ selectedSupervisor ? 'Pause selected' : 'Pause all' }}</button>
                <button
                    class="lf-control-btn lf-control-btn-primary"
                    type="button"
                    :disabled="selectedSupervisor
                        ? isControlling(selectedSupervisor.name + ':continue') || selectedSupervisor.status === 'inactive' || selectedSupervisor.status !== 'paused'
                        : isControlling('masters:continue') || supervisorSummary.paused === 0"
                    @click="requestSelectedAction('continue')"
                >{{ selectedSupervisor ? 'Resume selected' : 'Resume all' }}</button>
            </div>
        </div>

        <div class="lf-control-overview">
            <div class="lf-control-stat">
                <span class="lf-control-stat-value">{{ formatNumber(targetSummary.running) }}</span>
                <span class="lf-control-stat-label">Running</span>
            </div>
            <div class="lf-control-stat" :class="{ 'lf-control-stat-warning': targetSummary.paused > 0 }">
                <span class="lf-control-stat-value">{{ formatNumber(targetSummary.paused) }}</span>
                <span class="lf-control-stat-label">Paused</span>
            </div>
            <div class="lf-control-stat" :class="{ 'lf-control-stat-critical': targetSummary.inactive > 0 }">
                <span class="lf-control-stat-value">{{ formatNumber(targetSummary.inactive) }}</span>
                <span class="lf-control-stat-label">Inactive</span>
            </div>
            <div class="lf-control-stat">
                <span class="lf-control-stat-value">{{ formatNumber(targetSummary.processes) }}</span>
                <span class="lf-control-stat-label">Processes</span>
            </div>
        </div>

        <div class="lf-supervisors" :class="{ 'lf-supervisors-selected': selectedSupervisor }" v-if="supervisors.length">
            <div class="lf-supervisor" v-for="supervisor in visibleSupervisors" :key="supervisor.name">
                <div class="lf-supervisor-main">
                    <div class="lf-supervisor-title-row">
                        <div class="lf-supervisor-name">{{ supervisor.name }}</div>
                        <span class="lf-status" :class="'lf-status-' + statusKey(supervisor.status)">{{ supervisor.status }}</span>
                    </div>
                    <div class="lf-supervisor-details">
                        <div class="lf-supervisor-detail">
                            <span>Connection</span>
                            <strong>{{ supervisor.options?.connection ?? 'default' }}</strong>
                        </div>
                        <div class="lf-supervisor-detail">
                            <span>Queues</span>
                            <strong>{{ queueLabel(supervisor.options?.queue) }}</strong>
                        </div>
                        <div class="lf-supervisor-detail">
                            <span>Processes</span>
                            <strong>{{ formatNumber(processCount(supervisor.processes)) }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="lf-empty-sm" v-else>No Horizon supervisors detected.</div>
    </div>
</template>
