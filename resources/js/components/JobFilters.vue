<script type="text/ecmascript-6">
    /**
     * Search and filter controls shared by the job listing screens.
     *
     * The listings page through Redis by index, so there is no server-side
     * query to narrow: these controls filter the jobs already loaded. The
     * match count spells that out rather than implying a global search.
     */
    export default {
        props: {
            search: { type: String, default: '' },
            queue: { type: String, default: 'all' },
            status: { type: String, default: 'all' },
            queues: { type: Array, default: () => [] },
            statuses: { type: Array, default: () => [] },
            placeholder: { type: String, default: 'Search jobs…' },
            matched: { type: Number, default: 0 },
            total: { type: Number, default: 0 },
        },

        emits: ['update:search', 'update:queue', 'update:status', 'reset'],

        computed: {
            isFiltering() {
                return this.search.trim() !== '' || this.queue !== 'all' || this.status !== 'all';
            },
        },

        methods: {
            reset() {
                this.$emit('update:search', '');
                this.$emit('update:queue', 'all');
                this.$emit('update:status', 'all');
                this.$emit('reset');
            },
        },
    };
</script>

<template>
    <div class="job-filters d-flex flex-wrap align-items-center gap-2 px-3 py-2">
        <div class="form-control-with-icon job-filters-search">
            <div class="icon-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon">
                    <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                </svg>
            </div>

            <input
                type="search"
                class="form-control w-100"
                :value="search"
                :placeholder="placeholder"
                :aria-label="placeholder"
                @input="$emit('update:search', $event.target.value)"
            >
        </div>

        <select
            v-if="queues.length > 1"
            class="form-select form-select-sm job-filters-select"
            aria-label="Filter by queue"
            :value="queue"
            @change="$emit('update:queue', $event.target.value)"
        >
            <option value="all">All queues</option>
            <option v-for="name in queues" :key="name" :value="name">{{ name }}</option>
        </select>

        <select
            v-if="statuses.length > 1"
            class="form-select form-select-sm job-filters-select"
            aria-label="Filter by status"
            :value="status"
            @change="$emit('update:status', $event.target.value)"
        >
            <option value="all">All statuses</option>
            <option v-for="option in statuses" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>

        <slot></slot>

        <div class="ms-auto d-flex align-items-center gap-2">
            <small class="text-muted job-filters-count">
                <template v-if="isFiltering">{{ matched }} of {{ total }} on this page</template>
                <template v-else>{{ total }} on this page</template>
            </small>

            <button v-if="isFiltering" type="button" class="btn btn-secondary btn-sm" @click="reset">Clear</button>
        </div>
    </div>
</template>
