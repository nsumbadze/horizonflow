<script type="text/ecmascript-6">
    import Sparkline from './Sparkline.vue';

    export default {
        components: { Sparkline },

        props: {
            metrics: { type: Array, required: true },
        },

        methods: {
            sparkColor(metric) {
                return {
                    primary: 'var(--lf-violet)',
                    warn: 'var(--lf-amber)',
                    danger: 'var(--lf-red)',
                    ok: 'var(--lf-green)',
                }[metric.cls] ?? 'var(--lf-cyan)';
            },
        },
    };
</script>

<template>
    <div class="lf-metrics">
        <div
            v-for="m in metrics"
            :key="m.key"
            class="lf-metric"
        >
            <span class="lf-metric-label">{{ m.label }}</span>
            <span class="lf-metric-value" :class="m.cls ? 'lf-val-' + m.cls : ''">{{ m.value }}</span>
            <span class="lf-metric-sub">{{ m.sub }}</span>
            <Sparkline
                class="lf-metric-spark"
                :values="m.history ?? []"
                :color="sparkColor(m)"
                :height="20"
            />
        </div>
    </div>
</template>
