<script type="text/ecmascript-6">
    export default {
        props: {
            flow: { type: Object, default: null },
            sourceClass: { type: String, default: 'mock' },
            sourceLabel: { type: String, default: 'loading' },
            generatedAt: { type: String, default: null },
            refreshing: { type: Boolean, default: false },
            live: { type: Boolean, default: true },
            filterText: { type: String, default: '' },
            timeRange: { type: String, default: 'Last 15m' },
        },

        emits: ['update:filterText', 'update:timeRange', 'refresh', 'toggle-live'],

        computed: {
            filterModel: {
                get() { return this.filterText; },
                set(value) { this.$emit('update:filterText', value); },
            },

            timeRangeModel: {
                get() { return this.timeRange; },
                set(value) { this.$emit('update:timeRange', value); },
            },
        },
    };
</script>

<template>
    <div class="lf-toolbar">
        <span v-if="flow" class="lf-chip" :class="'lf-chip-' + sourceClass">
            {{ sourceLabel }}
        </span>
        <span v-if="generatedAt" class="lf-ts">{{ generatedAt }}</span>
        <div class="lf-toolbar-gap"></div>
        <input v-model="filterModel" type="text" class="lf-input" placeholder="filter queues…">
        <select v-model="timeRangeModel" class="lf-select" @change="$emit('refresh')">
            <optgroup label="Minutes">
                <option>Last 5m</option>
                <option>Last 15m</option>
            </optgroup>
            <optgroup label="Hours">
                <option>Last 1h</option>
                <option>Last 6h</option>
                <option>Last 24h</option>
            </optgroup>
            <optgroup label="Days">
                <option>Last 3d</option>
                <option>Last 7d</option>
                <option>Last 30d</option>
            </optgroup>
        </select>
        <button class="lf-btn" type="button" @click="$emit('refresh')">
            <svg :class="{ 'lf-spin': refreshing }" width="11" height="11" viewBox="0 0 12 12" fill="none">
                <path d="M10.5 2A5 5 0 1 0 11 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                <path d="M10.5 2V5H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            refresh
        </button>
        <button class="lf-btn" :class="{ 'lf-btn-live': live }" type="button" @click="$emit('toggle-live')">
            live
        </button>
    </div>
</template>
