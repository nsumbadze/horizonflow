<script type="text/ecmascript-6">
    import formatters from './formatters';

    export default {
        mixins: [formatters],

        props: {
            runs: { type: Array, default: () => [] },
            releasing: { type: Array, default: () => [] },
        },

        emits: ['release'],

        methods: {
            /**
             * How long a cancellation still stands, in seconds.
             */
            remaining(run) {
                return Math.max(0, Number(run.expires_at ?? 0) - Math.floor(Date.now() / 1000));
            },

            isReleasing(run) {
                return this.releasing.includes(run.group);
            },
        },
    };
</script>

<template>
    <div class="lf-runs" v-if="runs.length">
        <div class="lf-runs-head">
            <span class="lf-runs-title">Cancelled runs</span>
            <span class="lf-runs-scope">across every queue</span>
        </div>

        <div class="lf-run-row" v-for="run in runs" :key="run.group">
            <div class="lf-run-row-key">{{ run.group }}</div>

            <div class="lf-run-figures">
                <span class="lf-run-figure">
                    <b>{{ formatNumber(run.purged ?? 0) }}</b>
                    <span>purged</span>
                </span>
                <span class="lf-run-figure">
                    <b>{{ formatNumber(run.dropped ?? 0) }}</b>
                    <span>dropped</span>
                </span>
                <span class="lf-run-figure lf-run-figure-clock">
                    <b>{{ formatDuration(remaining(run)) }}</b>
                    <span>until it lifts</span>
                </span>
            </div>

            <button
                class="lf-mini-btn lf-mini-btn-safe"
                type="button"
                :disabled="isReleasing(run)"
                @click="$emit('release', run)"
            >{{ isReleasing(run) ? 'lifting…' : 'lift' }}</button>
        </div>
    </div>
</template>
