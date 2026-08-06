import moment from 'moment-timezone';

const LOCALSTORAGE_AUTOLOAD_KEY = 'horizonAutoLoadsNewEntries';

export default {
    computed: {
        Horizon() {
            return Horizon;
        },
    },

    methods: {
        /**
         * Format the given date with respect to timezone.
         */
        formatDate(unixTime) {
            return moment(unixTime * 1000).add(new Date().getTimezoneOffset() / 60);
        },

        /**
         * Format the given date with respect to timezone.
         */
        formatDateIso(date) {
            return moment(date).add(new Date().getTimezoneOffset() / 60);
        },

        /**
         * Extract the job base name.
         */
        jobBaseName(name) {
            if (!name.includes('\\')) return name;

            var parts = name.split('\\');

            return parts[parts.length - 1];
        },

        /**
         * Autoload new entries in listing screens.
         */
        autoLoadNewEntries() {
            this.autoLoadsNewEntries = !this.autoLoadsNewEntries;
            localStorage[LOCALSTORAGE_AUTOLOAD_KEY] = Number(this.autoLoadsNewEntries);
        },

        /**
         * Convert to human readable timestamp.
         */
        readableTimestamp(timestamp) {
            return this.formatDate(timestamp).format('YYYY-MM-DD HH:mm:ss');
        },

        /**
         * Convert a `datetime-local` input value to a unix timestamp.
         *
         * The inputs carry minute precision, so the upper bound covers the
         * whole minute the user picked rather than cutting it off at :00.
         */
        inputTimestamp(value, endOfMinute = false) {
            if (! value) {
                return null;
            }

            let parsed = new Date(value).getTime();

            if (Number.isNaN(parsed)) {
                return null;
            }

            return parsed / 1000 + (endOfMinute ? 59 : 0);
        },

        /**
         * Determine whether a unix timestamp falls inside the given range.
         */
        withinDateRange(timestamp, from, to) {
            let after = this.inputTimestamp(from);
            let before = this.inputTimestamp(to, true);

            if (after === null && before === null) {
                return true;
            }

            let ts = Number(timestamp ?? 0);

            if (! ts) {
                return false;
            }

            if (after !== null && ts < after) {
                return false;
            }

            return before === null || ts <= before;
        },

        /**
         * Uppercase the first character of the string.
         */
        upperFirst(string) {
            return string.charAt(0).toUpperCase() + string.slice(1);
        },

        /**
         * Group array entries by a given key.
         */
        groupBy(array, key) {
            return array.reduce(
                (grouped, entry) => ({
                    ...grouped,
                    [entry[key]]: [...(grouped[entry[key]] || []), entry],
                }),
                {}
            );
        },
    },
};
