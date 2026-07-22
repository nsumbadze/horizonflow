<script type="text/ecmascript-6">
    export default {
        props: {
            metrics: { type: Array, required: true },
        },

        emits: ['navigate'],
    };
</script>

<template>
    <div class="lf-metrics">
        <component
            v-for="m in metrics"
            :key="m.key"
            :is="m.to ? 'router-link' : (m.tab ? 'button' : 'div')"
            :to="m.to ?? undefined"
            :type="m.tab ? 'button' : undefined"
            class="lf-metric"
            :class="[
                m.cls ? 'lf-metric-' + m.cls : 'lf-metric-neutral',
                { 'lf-metric-action': m.to || m.tab },
            ]"
            @click="m.tab ? $emit('navigate', { tab: m.tab, mode: m.mode }) : null"
        >
            <div class="lf-metric-head">
                <span class="lf-metric-icon" aria-hidden="true">
                    <svg v-if="m.key === 'pending'" viewBox="0 0 20 20" fill="none"><path d="M4 5.5h12v9H4zM4 11h3l1.5 2h3l1.5-2h3" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                    <svg v-else-if="m.key === 'processing'" viewBox="0 0 20 20" fill="none"><path d="M6 4.5l8 5.5-8 5.5v-11z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                    <svg v-else-if="m.key === 'delayed'" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="6.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6.5V10l2.5 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <svg v-else-if="m.key === 'failed'" viewBox="0 0 20 20" fill="none"><path d="M10 3.5l7 12H3l7-12z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 7.5v3.5M10 13.5h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <svg v-else-if="m.key === 'flow'" viewBox="0 0 20 20" fill="none"><path d="M3.5 13.5l4-4 3 2 5.5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.5 5.5H16V9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <svg v-else viewBox="0 0 20 20" fill="none"><path d="M10 5v5l3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="6.5" stroke="currentColor" stroke-width="1.5"/></svg>
                </span>
                <span class="lf-metric-label">{{ m.label }}</span>
                <span class="lf-metric-open" v-if="m.to || m.tab" aria-hidden="true">↗</span>
            </div>
            <div class="lf-metric-body">
                <span class="lf-metric-value" :class="m.cls ? 'lf-val-' + m.cls : ''">{{ m.value }}</span>
                <span class="lf-metric-sub">{{ m.sub }}</span>
            </div>
        </component>
    </div>
</template>
