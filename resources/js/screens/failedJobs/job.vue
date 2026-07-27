<script type="text/ecmascript-6">
    import phpunserialize from 'phpunserialize'
    import StackTrace from '@/components/Stacktrace.vue'

    export default {
        components: {
            'stack-trace': StackTrace,
        },


        /**
         * The component's data.
         */
        data() {
            return {
                ready: false,
                retrying: false,
                job: {},
                showParameters: false,
                loadingParameters: false,
                parameters: null,
                parameterForm: {},
                parameterError: null
            };
        },


        computed: {
            /**
             * Determine whether the job exposes editable parameters.
             */
            hasEditableParameters() {
                return !! (this.parameters && this.parameters.editable);
            }
        },


        /**
         * Prepare the component.
         */
        mounted() {
            this.loadFailedJob(this.$route.params.jobId);

            document.title = "Horizon - Failed Jobs";
        },


        methods: {
            loadFailedJob(id) {
                this.ready = false;

                this.$http.get(Horizon.basePath + '/api/jobs/failed/' + id)
                    .then(response => {
                        this.job = response.data;

                        this.ready = true;
                    });
            },


            /**
             * Reload the job retries.
             */
            reloadRetries() {
                this.$http.get(Horizon.basePath + '/api/jobs/failed/' + this.$route.params.jobId)
                    .then(response => {
                        this.job.retried_by = response.data.retried_by;
                    });
            },


            /**
             * Retry the given failed job.
             */
            retry(id, parameters = {}) {
                if (this.retrying) {
                    return;
                }

                this.retrying = true;
                this.parameterError = null;

                this.$http.post(Horizon.basePath + '/api/jobs/retry/' + id, {parameters})
                    .then(() => {
                        setTimeout(() => {
                            this.reloadRetries();

                            this.retrying = false;
                        }, 3000);
                    })
                    .catch(error => {
                        this.retrying = false;

                        this.parameterError = error.response && error.response.data && error.response.data.message
                            ? error.response.data.message
                            : 'The job could not be retried.';
                    });
            },


            /**
             * Toggle the editable parameters panel.
             */
            toggleParameters() {
                this.showParameters = ! this.showParameters;

                if (this.showParameters && this.parameters === null) {
                    this.loadParameters();
                }
            },


            /**
             * Load the parameters that may be overridden for the job.
             */
            loadParameters() {
                this.loadingParameters = true;

                this.$http.get(Horizon.basePath + '/api/jobs/failed/' + this.$route.params.jobId + '/parameters')
                    .then(response => {
                        this.parameters = response.data;
                        this.parameterForm = this.buildParameterForm(response.data.parameters);
                        this.loadingParameters = false;
                    })
                    .catch(() => {
                        this.parameters = {editable: false, reason: 'The job parameters could not be loaded.', parameters: []};
                        this.loadingParameters = false;
                    });
            },


            /**
             * Build the editable form state for the given parameters.
             */
            buildParameterForm(parameters) {
                let form = {};

                parameters.filter(parameter => parameter.editable).forEach(parameter => {
                    form[parameter.name] = {
                        type: parameter.type,
                        nullable: parameter.nullable,
                        isNull: parameter.value === null,
                        value: this.stringifyParameter(parameter)
                    };
                });

                return form;
            },


            /**
             * Convert a parameter value into its editable representation.
             */
            stringifyParameter(parameter) {
                let value = parameter.value === null && parameter.default !== null && parameter.default !== undefined
                    ? parameter.default
                    : parameter.value;

                if (parameter.type === 'bool') {
                    return value === true;
                }

                if (Array.isArray(value) || (value !== null && typeof value === 'object')) {
                    return JSON.stringify(value, null, 2);
                }

                return value === null || value === undefined ? '' : String(value);
            },


            /**
             * Get the value shown for a parameter that may not be edited.
             */
            readOnlyValueFor(parameter) {
                if (parameter.preview !== null && parameter.preview !== undefined) {
                    return parameter.preview;
                }

                return parameter.default === null || parameter.default === undefined
                    ? ''
                    : JSON.stringify(parameter.default);
            },


            /**
             * Determine the input type to use for the given parameter.
             */
            inputTypeFor(parameter) {
                if (parameter.type === 'bool') {
                    return 'boolean';
                }

                if (parameter.type === 'array' || parameter.type === 'iterable') {
                    return 'json';
                }

                return parameter.type === 'int' || parameter.type === 'float' ? 'number' : 'text';
            },


            /**
             * Reset the form back to the job's original parameters.
             */
            resetParameters() {
                this.parameterError = null;
                this.parameterForm = this.buildParameterForm(this.parameters.parameters);
            },


            /**
             * Retry the job using the edited parameters.
             */
            retryWithParameters() {
                let parameters = {};

                for (const [name, field] of Object.entries(this.parameterForm)) {
                    if (field.isNull) {
                        parameters[name] = null;

                        continue;
                    }

                    if (field.type === 'bool') {
                        parameters[name] = !! field.value;

                        continue;
                    }

                    if (field.type === 'array' || field.type === 'iterable') {
                        try {
                            parameters[name] = JSON.parse(field.value);
                        } catch (error) {
                            this.parameterError = 'The ' + name + ' parameter must contain valid JSON.';

                            return;
                        }

                        continue;
                    }

                    parameters[name] = field.value;
                }

                this.retry(this.job.id, parameters);
            },


            /**
             * Pretty print serialized job.
             *
             * @param data
             * @returns {string}
             */
            prettyPrintJob(data) {
                try {
                    return data.command && !data.command.includes('CallQueuedClosure')
                        ? phpunserialize(data.command) : data;
                } catch (err) {
                    return data;
                }
            }
        }
    }
</script>

<template>
    <div>
        <poll @poll="reloadRetries" :immediate="false" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0" v-if="!ready">Job Preview</h2>
                <h2 class="h6 m-0" v-if="ready">{{job.name}}</h2>

                <div class="d-flex align-items-center">
                    <button class="btn btn-secondary me-2" v-if="ready" v-on:click.prevent="toggleParameters">
                        {{ showParameters ? 'Hide Parameters' : 'Edit Parameters' }}
                    </button>

                    <button class="btn btn-primary" v-on:click.prevent="retry(job.id)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon" fill="currentColor" :class="{spin: retrying}">
                            <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z" clip-rule="evenodd" />
                        </svg>

                        Retry
                    </button>
                </div>
            </div>

            <div v-if="!ready" class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin me-2 fill-text-color">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>

                <span>Loading...</span>
            </div>

            <div class="card-body card-bg-secondary" v-if="ready">
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">ID</div>
                    <div class="col">{{job.id}}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Connection</div>
                    <div class="col">{{job.connection}}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Queue</div>
                    <div class="col">{{job.queue}}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Attempts</div>
                    <div class="col">{{job.payload.attempts}}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Retries</div>
                    <div class="col">{{job.retried_by.length}}</div>
                </div>
                <div class="row mb-2" v-if="job.payload.retry_of">
                    <div class="col-md-2 text-muted">Retry of ID</div>
                    <div class="col">
                         <a :href="Horizon.basePath + '/failed/' + job.payload.retry_of">
                            {{ job.payload.retry_of }}
                        </a>
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Tags</div>
                    <div class="col">{{ job.payload.tags && job.payload.tags.length ? job.payload.tags.join(', ') : '' }}</div>
                </div>
                <div class="row mb-2" v-if="prettyPrintJob(job.payload.data).batchId">
                    <div class="col-md-2 text-muted">Batch</div>
                    <div class="col">
                        <router-link :to="{ name: 'batches-preview', params: { batchId: prettyPrintJob(job.payload.data).batchId }}">
                            {{ prettyPrintJob(job.payload.data).batchId }}
                        </router-link>
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Pushed</div>
                    <div class="col">{{ readableTimestamp(job.payload.pushedAt) }}</div>
                </div>
                <div class="row">
                    <div class="col-md-2 text-muted">Failed</div>
                    <div class="col">{{readableTimestamp(job.failed_at)}}</div>
                </div>
            </div>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready && showParameters">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Retry Parameters</h2>
            </div>

            <div v-if="loadingParameters" class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin me-2 fill-text-color">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>

                <span>Loading...</span>
            </div>

            <div class="card-body card-bg-secondary" v-if="! loadingParameters && parameters">
                <div class="alert alert-danger mb-3" v-if="parameterError">{{ parameterError }}</div>

                <p class="text-muted" :class="{'mb-0': ! parameters.parameters.length}" v-if="! hasEditableParameters">{{ parameters.reason }}</p>

                <div class="row mb-3" v-for="parameter in parameters.parameters" :key="parameter.name">
                    <div class="col-md-3">
                        <label class="mb-0" :class="{'text-muted': ! parameter.editable}" :for="'parameter-' + parameter.name">
                            {{ parameter.name }}
                        </label>
                        <div><small class="text-muted">{{ parameter.type }}</small></div>
                    </div>

                    <div class="col" v-if="parameter.editable && parameterForm[parameter.name]">
                        <select class="form-select" :id="'parameter-' + parameter.name" v-model="parameterForm[parameter.name].value"
                                v-if="parameter.type === 'bool'" :disabled="parameterForm[parameter.name].isNull">
                            <option :value="true">true</option>
                            <option :value="false">false</option>
                        </select>

                        <textarea class="form-control font-monospace" :id="'parameter-' + parameter.name" rows="4"
                                  v-model="parameterForm[parameter.name].value"
                                  v-else-if="parameter.type === 'array' || parameter.type === 'iterable'"
                                  :disabled="parameterForm[parameter.name].isNull"></textarea>

                        <input class="form-control" :id="'parameter-' + parameter.name" v-model="parameterForm[parameter.name].value"
                               :disabled="parameterForm[parameter.name].isNull"
                               :type="parameter.type === 'int' || parameter.type === 'float' ? 'number' : 'text'" v-else>

                        <div class="form-check mt-1" v-if="parameter.nullable">
                            <input class="form-check-input" type="checkbox" :id="'parameter-null-' + parameter.name"
                                   v-model="parameterForm[parameter.name].isNull">
                            <label class="form-check-label text-muted" :for="'parameter-null-' + parameter.name">
                                <small>Send as null</small>
                            </label>
                        </div>
                    </div>

                    <div class="col" v-else>
                        <div class="text-muted font-monospace pt-1">{{ readOnlyValueFor(parameter) || '—' }}</div>
                        <small class="text-muted">{{ parameter.reason }}</small>
                    </div>
                </div>

                <div class="d-flex align-items-center" v-if="hasEditableParameters">
                    <button class="btn btn-primary me-2" :disabled="retrying" v-on:click.prevent="retryWithParameters">
                        {{ retrying ? 'Retrying...' : 'Retry With Parameters' }}
                    </button>

                    <button class="btn btn-secondary" :disabled="retrying" v-on:click.prevent="resetParameters">
                        Reset
                    </button>
                </div>
            </div>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Exception</h2>
            </div>
            <div>
                <stack-trace :trace="job.exception.split('\n')"></stack-trace>
            </div>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Exception Context</h2>
            </div>

            <div class="card-body code-bg text-white">
                <vue-json-pretty :data="prettyPrintJob(job.context)"></vue-json-pretty>
            </div>
        </div>


        <div class="card overflow-hidden mt-4" v-if="ready">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Data</h2>
            </div>

            <div class="card-body code-bg text-white">
                <vue-json-pretty :data="prettyPrintJob(job.payload.data)"></vue-json-pretty>
            </div>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready && job.retried_by.length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Recent Retries</h2>
            </div>

            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>Status</th>
                    <th>ID</th>
                    <th class="text-end">Retry Time</th>
                </tr>
                </thead>

                <tbody>

                <tr v-for="retry in job.retried_by">
                    <td>
                        <svg v-if="retry.status == 'completed'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="fill-success" style="width: 1.5rem; height: 1.5rem;">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>

                        <svg v-if="retry.status == 'reserved' || retry.status == 'pending'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="fill-warning" style="width: 1.5rem; height: 1.5rem;">
                            <path fill-rule="evenodd" d="M2 10a8 8 0 1116 0 8 8 0 01-16 0zm5-2.25A.75.75 0 017.75 7h.5a.75.75 0 01.75.75v4.5a.75.75 0 01-.75.75h-.5a.75.75 0 01-.75-.75v-4.5zm4 0a.75.75 0 01.75-.75h.5a.75.75 0 01.75.75v4.5a.75.75 0 01-.75.75h-.5a.75.75 0 01-.75-.75v-4.5z" clip-rule="evenodd" />
                        </svg>

                        <svg v-if="retry.status == 'failed'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="fill-danger" style="width: 1.5rem; height: 1.5rem;">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                        </svg>

                        <span class="ms-2">{{ upperFirst(retry.status) }}</span>
                    </td>

                    <td class="table-fit">
                        <a v-if="retry.status == 'failed'" :href="Horizon.basePath + '/failed/'+retry.id">
                            {{ retry.id }}
                        </a>
                        <span v-else>{{ retry.id }}</span>
                    </td>

                    <td class="text-end table-fit text-muted">
                        {{readableTimestamp(retry.retried_at)}}
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

    </div>
</template>
