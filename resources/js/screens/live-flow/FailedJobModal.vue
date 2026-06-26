<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            job: { type: Object, default: null },
            details: { type: Object, default: null },
            loading: { type: Boolean, default: false },
            retrying: { type: Boolean, default: false },
        },

        emits: ['close', 'retry'],

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
