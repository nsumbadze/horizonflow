<script type="text/ecmascript-6">
    import JobRow from './job-row.vue';

    export default {
        /**
         * The component's data.
         */
        data() {
            return {
                ready: false,
                loadingNewEntries: false,
                hasNewEntries: false,
                page: 1,
                perPage: 50,
                totalPages: 1,
                jobs: [],
                searchPhrase: '',
                queueFilter: 'all',
                statusFilter: 'all',
            };
        },


        /**
         * Components
         */
        components: {
            JobRow,
        },


        computed: {
            /**
             * The queues present in the currently loaded page of jobs.
             */
            queueOptions() {
                return [...new Set(this.jobs.map(job => job.queue).filter(Boolean))].sort();
            },


            /**
             * The statuses present in the currently loaded page of jobs.
             */
            statusOptions() {
                return [...new Set(this.jobs.map(job => job.status).filter(Boolean))]
                    .sort()
                    .map(status => ({ value: status, label: this.upperFirst(status) }));
            },


            /**
             * Jobs on this page matching the search phrase and filters.
             */
            filteredJobs() {
                let phrase = this.searchPhrase.trim().toLowerCase();

                return this.jobs.filter(job => {
                    if (this.queueFilter !== 'all' && job.queue !== this.queueFilter) {
                        return false;
                    }

                    if (this.statusFilter !== 'all' && job.status !== this.statusFilter) {
                        return false;
                    }

                    if (! phrase) {
                        return true;
                    }

                    return [job.name, job.queue, job.id, ...(job.payload?.tags ?? [])]
                        .some(field => String(field ?? '').toLowerCase().includes(phrase));
                });
            },
        },


        /**
         * Prepare the component.
         */
        mounted() {
            this.updatePageTitle();

            this.loadJobs();
        },


        /**
         * Watch these properties for changes.
         */
        watch: {
            '$route'() {
                this.updatePageTitle();

                this.page = 1;

                this.resetFilters();

                this.loadJobs();
            },

            '$root.autoLoadsNewEntries'(autoLoadsNewEntries) {
                if (autoLoadsNewEntries && this.hasNewEntries) {
                    this.hasNewEntries = false;
                }
            }
        },


        methods: {
            /**
             * Clear the search phrase and filters.
             */
            resetFilters() {
                this.searchPhrase = '';
                this.queueFilter = 'all';
                this.statusFilter = 'all';
            },


            /**
             * Load the jobs of the given tag.
             */
            loadJobs(starting = -1, refreshing = false) {
                if (!refreshing) {
                    this.ready = false;
                }

                this.$http.get(Horizon.basePath + '/api/jobs/' + this.$route.params.type + '?starting_at=' + starting + '&limit=' + this.perPage)
                    .then(response => {
                        if (!this.$root.autoLoadsNewEntries && refreshing && this.jobs.length && response.data.jobs[0]?.id !== this.jobs[0]?.id) {
                            this.hasNewEntries = true;
                        } else {
                            this.jobs = response.data.jobs;

                            this.totalPages = Math.ceil(response.data.total / this.perPage);
                        }

                        this.ready = true;
                    });
            },


            loadNewEntries() {
                this.jobs = [];

                this.loadJobs(-1, false);

                this.hasNewEntries = false;
            },


            /**
             * Poll handler to refresh the jobs at regular intervals.
             */
            refreshJobsPeriodically() {
                if (this.page != 1) {
                    return;
                }

                this.loadJobs(-1, true);
            },


            /**
             * Load the jobs for the previous page.
             */
            previous() {
                this.loadJobs(
                    (this.page - 2) * this.perPage - 1
                );

                this.page -= 1;

                this.hasNewEntries = false;
            },


            /**
             * Load the jobs for the next page.
             */
            next() {
                this.loadJobs(
                    this.page * this.perPage - 1
                );

                this.page += 1;

                this.hasNewEntries = false;
            },


            /**
             * Update the page title.
             */
            updatePageTitle() {
                document.title = this.$route.params.type == 'pending'
                    ? 'Horizon - Pending Jobs'
                    : (
                        this.$route.params.type == 'silenced'
                            ? 'Horizon - Silenced Jobs'
                            : 'Horizon - Completed Jobs'
                    );
            }
        }
    }
</script>

<template>
    <div>
        <poll @poll="refreshJobsPeriodically" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0" v-if="$route.params.type == 'pending'">Pending Jobs</h2>
                <h2 class="h6 m-0" v-if="$route.params.type == 'completed'">Completed Jobs</h2>
                <h2 class="h6 m-0" v-if="$route.params.type == 'silenced'">Silenced Jobs</h2>
            </div>

            <job-filters
                v-if="ready && jobs.length > 0"
                v-model:search="searchPhrase"
                v-model:queue="queueFilter"
                v-model:status="statusFilter"
                :queues="queueOptions"
                :statuses="statusOptions"
                :matched="filteredJobs.length"
                :total="jobs.length"
                placeholder="Search job, queue, or tag"
            />

            <div v-if="!ready"
                 class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin me-2 fill-text-color">
                    <path
                        d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>

                <span>Loading...</span>
            </div>

            <div v-if="ready && jobs.length == 0"
                 class="d-flex flex-column align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <span v-if="$route.params.type == 'pending'">There aren't any pending jobs.</span>
                <span v-else-if="$route.params.type == 'completed'">There aren't any completed jobs.</span>
                <span v-else-if="$route.params.type == 'silenced'">There aren't any silenced jobs.</span>
                <span v-else>There aren't any jobs.</span>
            </div>

            <table v-if="ready && jobs.length > 0" class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th v-if="$route.params.type=='pending'">Queued</th>
                        <th v-if="$route.params.type=='pending'" class="text-end">Started</th>
                        <th v-if="$route.params.type=='completed' || $route.params.type=='silenced'">Queued</th>
                        <th v-if="$route.params.type=='completed' || $route.params.type=='silenced'">Started</th>
                        <th v-if="$route.params.type=='completed' || $route.params.type=='silenced'">Completed</th>
                        <th v-if="$route.params.type=='completed' || $route.params.type=='silenced'" class="text-end">Runtime</th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-if="hasNewEntries && !this.$root.autoLoadsNewEntries" key="newEntries" class="dontanimate">
                        <td colspan="100" class="text-center card-bg-secondary py-1">
                            <small><a href="#" v-on:click.prevent="loadNewEntries" v-if="!loadingNewEntries">Load New Entries</a></small>

                            <small v-if="loadingNewEntries">Loading...</small>
                        </td>
                    </tr>

                    <tr v-if="filteredJobs.length == 0" key="noMatches" class="dontanimate">
                        <td colspan="100" class="text-center card-bg-secondary py-3">
                            <small class="text-muted">No jobs on this page match your search.</small>
                        </td>
                    </tr>

                    <component v-for="job in filteredJobs" :key="job.id" :job="job" is="job-row">
                    </component>
                </tbody>
            </table>

            <div v-if="ready && jobs.length" class="p-3 d-flex justify-content-between border-top">
                <button @click="previous" class="btn btn-secondary btn-sm" :disabled="page==1">Previous</button>
                <button @click="next" class="btn btn-secondary btn-sm" :disabled="page>=totalPages">Next</button>
            </div>
        </div>
    </div>
</template>
