<script type="text/ecmascript-6">
    export default {
        props: {
            values: { type: Array, default: () => [] },
            color: { type: String, default: 'var(--lf-cyan)' },
            height: { type: Number, default: 22 },
        },

        computed: {
            series() {
                return this.values.map(Number).filter(Number.isFinite);
            },

            points() {
                const vals = this.series;
                if (vals.length < 2) return '';

                const min = Math.min(...vals);
                const max = Math.max(...vals);
                const span = max - min || 1;

                return vals.map((value, i) => {
                    const x = (i / (vals.length - 1)) * 100;
                    const y = 2 + (1 - (value - min) / span) * (this.height - 4);
                    return `${x.toFixed(1)},${y.toFixed(1)}`;
                }).join(' ');
            },

            areaPoints() {
                return this.points ? `0,${this.height} ${this.points} 100,${this.height}` : '';
            },
        },
    };
</script>

<template>
    <svg
        v-if="points"
        class="lf-spark"
        :viewBox="'0 0 100 ' + height"
        preserveAspectRatio="none"
        aria-hidden="true"
    >
        <polygon :points="areaPoints" :fill="color" opacity="0.08"/>
        <polyline
            :points="points"
            fill="none"
            :stroke="color"
            stroke-width="1.5"
            vector-effect="non-scaling-stroke"
            stroke-linejoin="round"
            stroke-linecap="round"
        />
    </svg>
</template>
