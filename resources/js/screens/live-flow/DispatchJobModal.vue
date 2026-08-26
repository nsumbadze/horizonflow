<script type="text/ecmascript-6">
    const DELAY_UNITS = [
        { key: 'seconds', label: 'sec', seconds: 1 },
        { key: 'minutes', label: 'min', seconds: 60 },
        { key: 'hours', label: 'hr', seconds: 3600 },
    ];

    export default {
        props: {
            open: { type: Boolean, default: false },
            catalog: { type: Object, default: null },
            loading: { type: Boolean, default: false },
            description: { type: Object, default: null },
            loadingParameters: { type: Boolean, default: false },
            dispatching: { type: Boolean, default: false },
            error: { type: String, default: null },
            mock: { type: Boolean, default: false },
            knownQueues: { type: Array, default: () => [] },
            preset: { type: Object, default: null },
        },

        emits: ['close', 'select-class', 'dispatch'],

        data() {
            return {
                filter: '',
                selectedClass: null,
                highlighted: 0,
                form: {},
                formError: null,
                options: { connection: '', queue: '', delay: '', unit: 'seconds' },
                units: DELAY_UNITS,
            };
        },

        computed: {
            jobs() {
                return this.catalog?.jobs ?? [];
            },

            connections() {
                return this.catalog?.connections ?? [];
            },

            /**
             * The job classes matching the current filter, in list order.
             */
            matches() {
                const needle = this.filter.trim().toLowerCase();

                if (needle === '') return this.jobs;

                return this.jobs.filter(job => (job.class ?? '').toLowerCase().includes(needle));
            },

            /**
             * The filtered classes grouped under the namespace they live in.
             */
            groupedMatches() {
                const groups = [];

                this.matches.forEach((job, index) => {
                    const namespace = job.namespace || 'Global';
                    let group = groups[groups.length - 1];

                    if (!group || group.namespace !== namespace) {
                        group = { namespace, jobs: [] };
                        groups.push(group);
                    }

                    group.jobs.push({ ...job, index });
                });

                return groups;
            },

            selectedJob() {
                return this.jobs.find(job => job.class === this.selectedClass) ?? null;
            },

            parameters() {
                return this.description?.parameters ?? [];
            },

            editableParameters() {
                return this.parameters.filter(parameter => parameter.editable);
            },

            /**
             * Whether the selected class can be dispatched as described.
             */
            dispatchable() {
                return !!(this.description && this.description.dispatchable);
            },

            /**
             * The connection the job will land on when none is chosen.
             */
            resolvedConnection() {
                return this.options.connection
                    || this.selectedJob?.connection
                    || this.catalog?.default_connection
                    || 'default';
            },

            /**
             * The queue the job will land on when none is chosen.
             */
            resolvedQueue() {
                if (this.options.queue.trim() !== '') return this.options.queue.trim();
                if (this.selectedJob?.queue) return this.selectedJob.queue;

                const connection = this.connections.find(item => item.name === this.resolvedConnection);

                return connection?.queue ?? 'default';
            },

            /**
             * The delay in whole seconds, or null when the input is not a number.
             */
            delaySeconds() {
                const raw = String(this.options.delay).trim();

                if (raw === '') return 0;
                if (!/^\d+$/.test(raw)) return null;

                const unit = this.units.find(item => item.key === this.options.unit) ?? this.units[0];

                return Number(raw) * unit.seconds;
            },

            maxDelay() {
                return Number(this.catalog?.max_delay ?? 86400);
            },

            /**
             * The delay written back in the unit the operator chose.
             */
            delayLabel() {
                if (!this.delaySeconds) return 'immediately';

                const unit = this.units.find(item => item.key === this.options.unit) ?? this.units[0];

                return `after ${String(this.options.delay).trim()} ${unit.label}`;
            },

            delayError() {
                if (this.delaySeconds === null) return 'Enter the delay as a whole number.';
                if (this.delaySeconds > this.maxDelay) return `The delay may not exceed ${this.maxDelay} seconds.`;

                return null;
            },

            /**
             * The queue names already seen on the chosen connection.
             */
            queueSuggestions() {
                const connection = this.resolvedConnection;

                return [...new Set(this.knownQueues
                    .filter(queue => !connection || queue.connection === connection)
                    .map(queue => queue.name)
                    .filter(Boolean))];
            },

            readyToDispatch() {
                return this.dispatchable && !this.dispatching && !this.delayError && !this.loadingParameters;
            },

            visibleError() {
                return this.formError ?? this.error;
            },
        },

        watch: {
            open(open) {
                if (open) this.prepare();
            },

            description() {
                this.form = this.buildForm();
                this.formError = null;
            },

            matches() {
                this.highlighted = 0;
            },
        },

        mounted() {
            if (this.open) this.prepare();
        },

        methods: {
            /**
             * Reset the panel for a freshly opened dispatch.
             */
            prepare() {
                this.filter = '';
                this.highlighted = 0;
                this.formError = null;
                this.selectedClass = null;
                this.form = {};
                this.options = {
                    connection: this.preset?.connection ?? '',
                    queue: this.preset?.queue ?? '',
                    delay: '',
                    unit: 'seconds',
                };

                this.$nextTick(() => this.$refs.filter?.focus());
            },

            selectClass(job) {
                if (!job || this.selectedClass === job.class) return;

                this.selectedClass = job.class;
                this.formError = null;
                this.$emit('select-class', job.class);
            },

            /**
             * Move the highlight through the filtered classes.
             */
            moveHighlight(step) {
                const total = this.matches.length;

                if (total === 0) return;

                this.highlighted = (this.highlighted + step + total) % total;
                this.$nextTick(() => {
                    this.$refs.list
                        ?.querySelector('.lf-dispatch-option-highlighted')
                        ?.scrollIntoView({ block: 'nearest' });
                });
            },

            chooseHighlighted() {
                this.selectClass(this.matches[this.highlighted]);
            },

            /**
             * Build the editable form state for the described parameters.
             */
            buildForm() {
                const form = {};

                this.editableParameters.forEach(parameter => {
                    form[parameter.name] = {
                        type: parameter.type,
                        nullable: parameter.nullable,
                        required: parameter.required,
                        isNull: false,
                        value: this.initialValue(parameter),
                    };
                });

                return form;
            },

            /**
             * Get the value a parameter's field starts with.
             */
            initialValue(parameter) {
                const value = parameter.default;

                if (parameter.type === 'bool') return value === true;

                if (Array.isArray(value) || (value !== null && typeof value === 'object')) {
                    return JSON.stringify(value, null, 2);
                }

                return value === null || value === undefined ? '' : String(value);
            },

            /**
             * Get the help text shown under a parameter's field.
             */
            parameterHint(parameter) {
                if (!parameter.editable) return parameter.reason;
                if (parameter.required) return 'Required.';

                const value = parameter.default;

                if (value === null || value === undefined) return 'Optional. Defaults to null.';

                return `Optional. Defaults to ${JSON.stringify(value)}.`;
            },

            resetForm() {
                this.form = this.buildForm();
                this.formError = null;
            },

            /**
             * Collect the constructor arguments the operator supplied.
             */
            collectParameters() {
                const parameters = {};

                for (const [name, field] of Object.entries(this.form)) {
                    if (field.isNull) {
                        parameters[name] = null;

                        continue;
                    }

                    if (field.type === 'bool') {
                        parameters[name] = !!field.value;

                        continue;
                    }

                    if (field.type === 'array' || field.type === 'iterable') {
                        if (String(field.value).trim() === '') {
                            if (field.required) {
                                this.formError = `Enter JSON for the ${name} parameter.`;

                                return null;
                            }

                            continue;
                        }

                        try {
                            parameters[name] = JSON.parse(field.value);
                        } catch (error) {
                            this.formError = `The ${name} parameter must contain valid JSON.`;

                            return null;
                        }

                        continue;
                    }

                    if (String(field.value).trim() === '' && !field.required) {
                        continue;
                    }

                    parameters[name] = field.value;
                }

                return parameters;
            },

            dispatch() {
                if (!this.readyToDispatch) return;

                this.formError = null;

                const parameters = this.collectParameters();

                if (parameters === null) return;

                this.$emit('dispatch', {
                    class: this.selectedClass,
                    name: this.selectedJob?.name ?? this.selectedClass,
                    parameters,
                    connection: this.options.connection || null,
                    queue: this.options.queue.trim() || null,
                    delay: this.delaySeconds,
                    resolved: { connection: this.resolvedConnection, queue: this.resolvedQueue },
                });
            },
        },
    };
</script>

<template>
    <div
        v-if="open"
        class="lf-modal-backdrop"
        @click.self="$emit('close')"
        @keydown.esc="$emit('close')"
    >
        <div class="lf-dispatch" role="dialog" aria-modal="true" aria-labelledby="lf-dispatch-title">
            <div class="lf-modal-head">
                <div>
                    <div class="lf-modal-kicker lf-dispatch-kicker">Dispatch</div>
                    <div class="lf-modal-title" id="lf-dispatch-title">Put a job on a queue</div>
                </div>
                <button class="lf-modal-close" type="button" aria-label="Close dispatch panel" @click="$emit('close')">×</button>
            </div>

            <div class="lf-dispatch-body">
                <!-- picker -->
                <div class="lf-dispatch-picker">
                    <input
                        ref="filter"
                        v-model="filter"
                        type="search"
                        class="lf-dispatch-filter"
                        placeholder="Filter job classes"
                        aria-label="Filter job classes"
                        @keydown.down.prevent="moveHighlight(1)"
                        @keydown.up.prevent="moveHighlight(-1)"
                        @keydown.enter.prevent="chooseHighlighted"
                    >

                    <div class="lf-dispatch-list" ref="list" role="listbox" aria-label="Dispatchable job classes">
                        <div class="lf-dispatch-list-note" v-if="loading">Loading job classes…</div>

                        <div class="lf-dispatch-list-note" v-else-if="!jobs.length">
                            No dispatchable jobs were found. Discovery walks <code>app/Jobs</code>; list classes elsewhere under
                            <code>horizonxflow.dispatch.allowed</code>.
                        </div>

                        <div class="lf-dispatch-list-note" v-else-if="!matches.length">
                            No job class matches “{{ filter }}”.
                        </div>

                        <template v-else v-for="group in groupedMatches" :key="group.namespace">
                            <div class="lf-dispatch-group">{{ group.namespace }}</div>
                            <button
                                v-for="job in group.jobs"
                                :key="job.class"
                                class="lf-dispatch-option"
                                :class="{
                                    'lf-dispatch-option-active': selectedClass === job.class,
                                    'lf-dispatch-option-highlighted': highlighted === job.index,
                                }"
                                type="button"
                                role="option"
                                :aria-selected="selectedClass === job.class"
                                @click="selectClass(job)"
                                @mouseenter="highlighted = job.index"
                            >
                                <span class="lf-dispatch-option-name">{{ job.name }}</span>
                                <span class="lf-dispatch-option-queue" v-if="job.queue">{{ job.queue }}</span>
                            </button>
                        </template>
                    </div>
                </div>

                <!-- job -->
                <div class="lf-dispatch-detail">
                    <div class="lf-dispatch-empty" v-if="!selectedClass">
                        <div class="lf-dispatch-empty-title">Choose a job class</div>
                        <div class="lf-dispatch-empty-text">
                            Pick a class on the left to fill in its constructor arguments, then choose where and when it runs.
                        </div>
                    </div>

                    <template v-else>
                        <div class="lf-dispatch-class">{{ selectedClass }}</div>

                        <div class="lf-dispatch-note" v-if="loadingParameters">Reading constructor parameters…</div>

                        <div class="lf-dispatch-blocked" v-else-if="!dispatchable">
                            {{ description?.reason ?? 'This job may not be dispatched from the dashboard.' }}
                        </div>

                        <template v-else>
                            <div class="lf-dispatch-note" v-if="!parameters.length">
                                This job takes no constructor arguments.
                            </div>

                            <div class="lf-dispatch-fields" v-else>
                                <div
                                    class="lf-dispatch-field"
                                    :class="{ 'lf-dispatch-field-locked': !parameter.editable }"
                                    v-for="parameter in parameters"
                                    :key="parameter.name"
                                >
                                    <label class="lf-dispatch-label" :for="'lf-dispatch-' + parameter.name">
                                        <span class="lf-dispatch-param">{{ parameter.name }}</span>
                                        <span class="lf-dispatch-type">{{ parameter.type }}</span>
                                        <span class="lf-dispatch-required" v-if="parameter.required">required</span>
                                    </label>

                                    <template v-if="parameter.editable && form[parameter.name]">
                                        <select
                                            class="lf-dispatch-control"
                                            :id="'lf-dispatch-' + parameter.name"
                                            v-model="form[parameter.name].value"
                                            v-if="parameter.type === 'bool'"
                                            :disabled="form[parameter.name].isNull"
                                        >
                                            <option :value="true">true</option>
                                            <option :value="false">false</option>
                                        </select>

                                        <textarea
                                            class="lf-dispatch-control lf-dispatch-control-mono"
                                            :id="'lf-dispatch-' + parameter.name"
                                            rows="3"
                                            spellcheck="false"
                                            placeholder="[]"
                                            v-model="form[parameter.name].value"
                                            v-else-if="parameter.type === 'array' || parameter.type === 'iterable'"
                                            :disabled="form[parameter.name].isNull"
                                        ></textarea>

                                        <input
                                            class="lf-dispatch-control"
                                            :id="'lf-dispatch-' + parameter.name"
                                            v-model="form[parameter.name].value"
                                            :type="parameter.type === 'int' || parameter.type === 'float' ? 'number' : 'text'"
                                            :disabled="form[parameter.name].isNull"
                                            v-else
                                        >

                                        <div class="lf-dispatch-hint">
                                            <span>{{ parameterHint(parameter) }}</span>
                                            <label class="lf-dispatch-null" v-if="parameter.nullable">
                                                <input type="checkbox" v-model="form[parameter.name].isNull">
                                                send as null
                                            </label>
                                        </div>
                                    </template>

                                    <template v-else>
                                        <div class="lf-dispatch-readonly">{{ parameter.preview ?? '—' }}</div>
                                        <div class="lf-dispatch-hint"><span>{{ parameterHint(parameter) }}</span></div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </template>
                </div>
            </div>

            <!-- route -->
            <div class="lf-dispatch-route" :aria-disabled="!selectedClass">
                <div class="lf-dispatch-route-head">Route</div>

                <div class="lf-dispatch-route-leg">
                    <label class="lf-dispatch-leg-label" for="lf-dispatch-connection">connection</label>
                    <select class="lf-dispatch-control" id="lf-dispatch-connection" v-model="options.connection">
                        <option value="">{{ resolvedConnection }} (default)</option>
                        <option v-for="connection in connections" :key="connection.name" :value="connection.name">
                            {{ connection.name }}{{ connection.driver ? ' · ' + connection.driver : '' }}
                        </option>
                    </select>
                </div>

                <span class="lf-dispatch-route-arrow" aria-hidden="true">›</span>

                <div class="lf-dispatch-route-leg">
                    <label class="lf-dispatch-leg-label" for="lf-dispatch-queue">queue</label>
                    <input
                        class="lf-dispatch-control lf-dispatch-control-mono"
                        id="lf-dispatch-queue"
                        list="lf-dispatch-queues"
                        v-model="options.queue"
                        :placeholder="resolvedQueue"
                        spellcheck="false"
                    >
                    <datalist id="lf-dispatch-queues">
                        <option v-for="queue in queueSuggestions" :key="queue" :value="queue"></option>
                    </datalist>
                </div>

                <span class="lf-dispatch-route-arrow" aria-hidden="true">›</span>

                <div class="lf-dispatch-route-leg">
                    <label class="lf-dispatch-leg-label" for="lf-dispatch-delay">delay</label>
                    <div class="lf-dispatch-delay">
                        <input
                            class="lf-dispatch-control"
                            id="lf-dispatch-delay"
                            type="number"
                            min="0"
                            placeholder="0"
                            v-model="options.delay"
                        >
                        <select class="lf-dispatch-control" v-model="options.unit" aria-label="Delay unit">
                            <option v-for="unit in units" :key="unit.key" :value="unit.key">{{ unit.label }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="lf-dispatch-foot">
                <div class="lf-dispatch-status">
                    <div class="lf-dispatch-error" v-if="visibleError">{{ visibleError }}</div>
                    <div class="lf-dispatch-error" v-else-if="delayError">{{ delayError }}</div>
                    <div class="lf-dispatch-demo" v-else-if="mock">Demo data — dispatching is simulated and never reaches Redis.</div>
                    <div class="lf-dispatch-summary" v-else-if="selectedClass && dispatchable">
                        Runs on <b>{{ resolvedConnection }}</b> · <b>{{ resolvedQueue }}</b> {{ delayLabel }}.
                    </div>
                </div>

                <button class="lf-control-btn" type="button" :disabled="dispatching" @click="resetForm" v-if="selectedClass && dispatchable">
                    Reset
                </button>
                <button
                    class="lf-control-btn lf-dispatch-go"
                    type="button"
                    :disabled="!readyToDispatch"
                    @click="dispatch"
                >{{ dispatching ? 'dispatching…' : 'Dispatch job' }}</button>
            </div>
        </div>
    </div>
</template>
