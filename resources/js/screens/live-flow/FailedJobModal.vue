<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            job: { type: Object, default: null },
            details: { type: Object, default: null },
            loading: { type: Boolean, default: false },
            retrying: { type: Boolean, default: false },
            run: { type: Object, default: null },
            cancellingRun: { type: Boolean, default: false },
        },

        emits: ['close', 'retry', 'cancel-run'],

        computed: {
            modalJobName() {
                return this.details?.name ?? this.job?.name ?? 'Queued job';
            },

            modalJobError() {
                return this.details?.exception ?? this.job?.exception ?? 'No exception text was captured.';
            },

            href() {
                const j = this.job;
                if (!j?.id) return null;
                if (j.inspectable === false) return null;
                if (j.status === 'failed') return `${Horizon.basePath}/failed/${j.id}`;
                if (j.status === 'completed') return `${Horizon.basePath}/jobs/completed/${j.id}`;
                return `${Horizon.basePath}/jobs/pending/${j.id}`;
            },
        },

    };
</script>

<template>
    <div
        v-if="job"
        class="lf-modal-backdrop"
        tabindex="-1"
        @click.self="$emit('close')"
        @keydown.esc="$emit('close')"
    >
        <div class="lf-modal">
            <div class="lf-modal-head">
                <div>
                    <div class="lf-modal-kicker">Failed Job</div>
                    <div class="lf-modal-title">{{ shortJobName(modalJobName) }}</div>
                </div>
                <button class="lf-modal-close" type="button" @click="$emit('close')">×</button>
            </div>

            <div class="lf-modal-meta">
                <span>{{ job.connection }} · {{ job.queue }}</span>
                <span>attempts {{ formatNumber(job.attempts ?? 0) }}</span>
                <span>age {{ formatDuration(job.age_seconds) }}</span>
            </div>

            <div class="lf-modal-loading" v-if="loading">Loading full Horizon failure payload…</div>
            <pre class="lf-modal-error" v-else>{{ modalJobError }}</pre>

            <div class="lf-run" v-if="run && run.group">
                <div class="lf-run-head">
                    <span class="lf-run-kicker">Run</span>
                    <span class="lf-run-key">{{ run.group }}</span>
                </div>

                <template v-if="run.run">
                    <p class="lf-run-text">
                        This run is already cancelled. Its queued jobs are dropped as workers reach them, and nothing new
                        carrying this key will start until the block lifts.
                    </p>
                </template>

                <template v-else>
                    <p class="lf-run-text">
                        Cancelling this one job leaves the rest of the run going — a chained job simply queues its
                        successor. Cancel the run to stop every job carrying this key.
                    </p>
                    <button
                        class="lf-mini-btn lf-mini-btn-danger"
                        type="button"
                        :disabled="cancellingRun"
                        @click="$emit('cancel-run', run.group)"
                    >{{ cancellingRun ? 'cancelling…' : 'cancel run' }}</button>
                </template>
            </div>

            <div class="lf-modal-actions">
                <a class="lf-btn" :href="href" v-if="href">open in Horizon</a>
                <button
                    class="lf-btn lf-btn-live"
                    type="button"
                    v-if="job.retryable"
                    :disabled="retrying"
                    @click="$emit('retry', job)"
                >
                    {{ retrying ? 'retrying…' : 'retry failed job' }}
                </button>
            </div>
        </div>
    </div>
</template>
