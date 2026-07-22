<script type="text/ecmascript-6">
    export default {
        props: {
            metrics: { type: Array, required: true },
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
        </div>
    </div>
</template>
