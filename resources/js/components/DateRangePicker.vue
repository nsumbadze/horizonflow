<script type="text/ecmascript-6">
    /**
     * A date + time range picker used by the job listings and the live-flow
     * activity feed.
     *
     * Values go in and out as `datetime-local` strings (`YYYY-MM-DDTHH:mm`),
     * so callers filter with the same comparison they would use against a
     * native input. The popover is `position: fixed` but stays in the DOM
     * tree: that escapes the `overflow: hidden` on the listing cards while
     * still inheriting the surrounding theme's custom properties.
     */
    const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

    const MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    const PRESETS = [
        { key: '15m', label: 'Last 15 minutes', minutes: 15 },
        { key: '1h', label: 'Last hour', minutes: 60 },
        { key: '6h', label: 'Last 6 hours', minutes: 60 * 6 },
        { key: '24h', label: 'Last 24 hours', minutes: 60 * 24 },
        { key: '7d', label: 'Last 7 days', minutes: 60 * 24 * 7 },
        { key: 'today', label: 'Today' },
        { key: 'yesterday', label: 'Yesterday' },
    ];

    const pad = value => String(value).padStart(2, '0');

    const dayKey = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

    const timeKey = date => `${pad(date.getHours())}:${pad(date.getMinutes())}`;

    const parseValue = value => {
        let match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2})/.exec(String(value ?? ''));

        return match ? { day: `${match[1]}-${match[2]}-${match[3]}`, time: match[4] } : null;
    };

    export default {
        props: {
            from: { type: String, default: '' },
            to: { type: String, default: '' },
            label: { type: String, default: 'Any time' },
            variant: { type: String, default: 'default' },
        },

        emits: ['update:from', 'update:to'],

        data() {
            let today = new Date();

            return {
                open: false,
                draftStart: null,
                draftEnd: null,
                startTime: '00:00',
                endTime: '23:59',
                hoverDay: null,
                viewYear: today.getFullYear(),
                viewMonth: today.getMonth(),
                placement: { top: '0px', left: '0px' },
                weekdays: WEEKDAYS,
                presets: PRESETS,
            };
        },

        computed: {
            hasRange() {
                return this.from !== '' || this.to !== '';
            },

            /**
             * The text on the trigger: what is currently filtering, not what
             * is being drafted inside an open popover.
             */
            summary() {
                let start = parseValue(this.from);
                let end = parseValue(this.to);

                if (! start && ! end) return this.label;
                if (start && ! end) return `After ${this.readableBound(start)}`;
                if (! start && end) return `Before ${this.readableBound(end)}`;

                return `${this.readableBound(start)} – ${this.readableBound(end)}`;
            },

            monthLabel() {
                return `${MONTHS[this.viewMonth]} ${this.viewYear}`;
            },

            /**
             * The visible month as six Monday-first weeks, so the grid height
             * never jumps as you page through months.
             */
            weeks() {
                let first = new Date(this.viewYear, this.viewMonth, 1);
                let cursor = new Date(this.viewYear, this.viewMonth, 1 - ((first.getDay() + 6) % 7));
                let today = dayKey(new Date());
                let weeks = [];

                for (let week = 0; week < 6; week++) {
                    let days = [];

                    for (let index = 0; index < 7; index++) {
                        let key = dayKey(cursor);

                        days.push({
                            key,
                            number: cursor.getDate(),
                            outside: cursor.getMonth() !== this.viewMonth,
                            today: key === today,
                            start: key === this.draftStart,
                            end: key === this.effectiveEnd,
                            between: this.isBetween(key),
                        });

                        cursor.setDate(cursor.getDate() + 1);
                    }

                    weeks.push(days);
                }

                return weeks;
            },

            /**
             * While only the start is picked, hovering previews the end so the
             * highlighted band follows the pointer.
             */
            effectiveEnd() {
                if (this.draftEnd) return this.draftEnd;
                if (this.draftStart && this.hoverDay && this.hoverDay > this.draftStart) return this.hoverDay;

                return null;
            },

            draftSummary() {
                if (! this.draftStart) return 'Pick a start day';
                if (! this.draftEnd) return 'Pick an end day';

                return `${this.draftStart} ${this.startTime} – ${this.draftEnd} ${this.endTime}`;
            },
        },

        beforeUnmount() {
            this.unbind();
        },

        methods: {
            readableBound(parsed) {
                let [year, month, day] = parsed.day.split('-').map(Number);
                let sameYear = year === new Date().getFullYear();

                return `${MONTHS[month - 1].slice(0, 3)} ${day}${sameYear ? '' : ', ' + year} ${parsed.time}`;
            },

            isBetween(key) {
                let end = this.effectiveEnd;

                return Boolean(this.draftStart && end && key > this.draftStart && key < end);
            },

            toggle() {
                this.open ? this.close() : this.reveal();
            },

            reveal() {
                let start = parseValue(this.from);
                let end = parseValue(this.to);

                this.draftStart = start?.day ?? null;
                this.draftEnd = end?.day ?? null;
                this.startTime = start?.time ?? '00:00';
                this.endTime = end?.time ?? '23:59';
                this.hoverDay = null;

                let anchor = start?.day ?? end?.day;

                if (anchor) {
                    let [year, month] = anchor.split('-').map(Number);
                    this.viewYear = year;
                    this.viewMonth = month - 1;
                }

                this.open = true;

                this.$nextTick(() => {
                    this.reposition();
                    this.bind();
                });
            },

            close() {
                this.open = false;
                this.hoverDay = null;
                this.unbind();
            },

            bind() {
                window.addEventListener('resize', this.reposition);
                window.addEventListener('scroll', this.reposition, true);
                document.addEventListener('mousedown', this.closeOnOutsideClick);
                document.addEventListener('keydown', this.closeOnEscape);
            },

            unbind() {
                window.removeEventListener('resize', this.reposition);
                window.removeEventListener('scroll', this.reposition, true);
                document.removeEventListener('mousedown', this.closeOnOutsideClick);
                document.removeEventListener('keydown', this.closeOnEscape);
            },

            closeOnOutsideClick(event) {
                if (! this.$el.contains(event.target)) {
                    this.close();
                }
            },

            closeOnEscape(event) {
                if (event.key === 'Escape') {
                    this.close();
                    this.$refs.trigger?.focus();
                }
            },

            /**
             * Anchor the popover under the trigger, flipping above it and
             * clamping to the viewport rather than letting it run off screen.
             */
            reposition() {
                let trigger = this.$refs.trigger;
                let popover = this.$refs.popover;

                if (! trigger || ! popover) return;

                let anchor = trigger.getBoundingClientRect();
                let width = popover.offsetWidth;
                let height = popover.offsetHeight;
                let gap = 6;

                let top = anchor.bottom + gap;

                if (top + height > window.innerHeight - 8 && anchor.top - gap - height > 8) {
                    top = anchor.top - gap - height;
                }

                let left = Math.min(anchor.left, window.innerWidth - width - 8);

                this.placement = {
                    top: `${Math.max(8, top)}px`,
                    left: `${Math.max(8, left)}px`,
                };
            },

            shiftMonth(delta) {
                let shifted = new Date(this.viewYear, this.viewMonth + delta, 1);

                this.viewYear = shifted.getFullYear();
                this.viewMonth = shifted.getMonth();
            },

            selectDay(day) {
                if (! this.draftStart || this.draftEnd || day.key < this.draftStart) {
                    this.draftStart = day.key;
                    this.draftEnd = null;
                } else {
                    this.draftEnd = day.key;
                }

                this.hoverDay = null;
            },

            applyPreset(preset) {
                let now = new Date();
                let start = new Date(now);
                let end = new Date(now);

                if (preset.minutes) {
                    start = new Date(now.getTime() - preset.minutes * 60000);
                } else if (preset.key === 'today') {
                    start.setHours(0, 0, 0, 0);
                } else {
                    start.setDate(start.getDate() - 1);
                    start.setHours(0, 0, 0, 0);
                    end = new Date(start);
                    end.setHours(23, 59, 0, 0);
                }

                this.$emit('update:from', `${dayKey(start)}T${timeKey(start)}`);
                this.$emit('update:to', `${dayKey(end)}T${timeKey(end)}`);

                this.close();
            },

            apply() {
                this.$emit('update:from', this.draftStart ? `${this.draftStart}T${this.startTime}` : '');
                this.$emit('update:to', this.draftEnd ? `${this.draftEnd}T${this.endTime}` : '');

                this.close();
            },

            clear() {
                this.$emit('update:from', '');
                this.$emit('update:to', '');

                this.close();
            },
        },
    };
</script>

<template>
    <div class="hf-dr" :class="'hf-dr-' + variant">
        <button
            ref="trigger"
            class="hf-dr-trigger"
            :class="{ 'hf-dr-trigger-set': hasRange, 'hf-dr-trigger-open': open }"
            type="button"
            :aria-expanded="open"
            aria-haspopup="dialog"
            @click="toggle"
        >
            <svg viewBox="0 0 20 20" aria-hidden="true">
                <path d="M6.75 2a.75.75 0 01.75.75V4h5V2.75a.75.75 0 011.5 0V4h.75A2.25 2.25 0 0117 6.25v9.5A2.25 2.25 0 0114.75 18h-9.5A2.25 2.25 0 013 15.75v-9.5A2.25 2.25 0 015.25 4H6V2.75A.75.75 0 016.75 2zM4.5 8.5v7.25c0 .414.336.75.75.75h9.5a.75.75 0 00.75-.75V8.5h-11z"/>
            </svg>
            <span class="hf-dr-trigger-text">{{ summary }}</span>
            <span class="hf-dr-trigger-caret" aria-hidden="true">▾</span>
        </button>

        <div
            v-show="open"
            ref="popover"
            class="hf-dr-pop"
            :style="placement"
            role="dialog"
            aria-label="Choose a date and time range"
        >
            <div class="hf-dr-body">
                <div class="hf-dr-presets">
                    <button
                        v-for="preset in presets"
                        :key="preset.key"
                        class="hf-dr-preset"
                        type="button"
                        @click="applyPreset(preset)"
                    >{{ preset.label }}</button>
                </div>

                <div class="hf-dr-cal">
                    <div class="hf-dr-cal-head">
                        <button class="hf-dr-nav" type="button" aria-label="Previous month" @click="shiftMonth(-1)">‹</button>
                        <span class="hf-dr-month">{{ monthLabel }}</span>
                        <button class="hf-dr-nav" type="button" aria-label="Next month" @click="shiftMonth(1)">›</button>
                    </div>

                    <div class="hf-dr-weekdays" aria-hidden="true">
                        <span v-for="weekday in weekdays" :key="weekday">{{ weekday }}</span>
                    </div>

                    <div class="hf-dr-grid" @mouseleave="hoverDay = null">
                        <template v-for="(week, index) in weeks" :key="index">
                            <button
                                v-for="day in week"
                                :key="day.key"
                                class="hf-dr-day"
                                :class="{
                                    'hf-dr-day-outside': day.outside,
                                    'hf-dr-day-today': day.today,
                                    'hf-dr-day-start': day.start,
                                    'hf-dr-day-end': day.end,
                                    'hf-dr-day-between': day.between,
                                }"
                                type="button"
                                @click="selectDay(day)"
                                @mouseenter="hoverDay = day.key"
                            >{{ day.number }}</button>
                        </template>
                    </div>

                    <div class="hf-dr-times">
                        <label>
                            <span>From</span>
                            <input type="time" v-model="startTime" :disabled="! draftStart">
                        </label>
                        <label>
                            <span>To</span>
                            <input type="time" v-model="endTime" :disabled="! draftEnd">
                        </label>
                    </div>
                </div>
            </div>

            <div class="hf-dr-foot">
                <span class="hf-dr-foot-text">{{ draftSummary }}</span>
                <div class="hf-dr-foot-actions">
                    <button class="hf-dr-btn" type="button" @click="clear">Clear</button>
                    <button class="hf-dr-btn hf-dr-btn-primary" type="button" :disabled="! draftStart" @click="apply">Apply</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
    .hf-dr {
        --hf-dr-bg: #ffffff;
        --hf-dr-surface: #f3f4f6;
        --hf-dr-border: #e5e7eb;
        --hf-dr-text: #111827;
        --hf-dr-muted: #6b7280;
        --hf-dr-accent: #7746ec;
        --hf-dr-accent-soft: rgba(119, 70, 236, .12);
        --hf-dr-on-accent: #ffffff;
        --hf-dr-shadow: 0 12px 32px -8px rgba(16, 20, 28, .28);

        position: relative;
        display: inline-flex;
    }

    .hf-dr-trigger {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        max-width: 100%;
        height: 31px;
        padding: 0 10px;
        border: 1px solid var(--hf-dr-border);
        border-radius: 9999px;
        background: var(--hf-dr-bg);
        color: var(--hf-dr-muted);
        font-family: inherit;
        font-size: 0.8125rem;
        line-height: 1;
        white-space: nowrap;
        cursor: pointer;
        transition: border-color .12s, color .12s;
    }
    .hf-dr-trigger svg { width: 13px; height: 13px; fill: currentColor; flex-shrink: 0; }
    .hf-dr-trigger-text { overflow: hidden; text-overflow: ellipsis; }
    .hf-dr-trigger-caret { font-size: 9px; opacity: .7; }
    .hf-dr-trigger:hover { border-color: var(--hf-dr-accent); color: var(--hf-dr-text); }
    .hf-dr-trigger-open { border-color: var(--hf-dr-accent); color: var(--hf-dr-text); }
    .hf-dr-trigger-set { border-color: var(--hf-dr-accent); background: var(--hf-dr-accent-soft); color: var(--hf-dr-text); }
    .hf-dr-trigger:focus-visible { outline: 2px solid var(--hf-dr-accent); outline-offset: 2px; }

    /* Fixed, but still a descendant: escapes the listing card's overflow clip
       without losing the theme variables it inherits. */
    .hf-dr-pop {
        position: fixed;
        z-index: 1080;
        width: 460px;
        max-width: calc(100vw - 16px);
        border: 1px solid var(--hf-dr-border);
        border-radius: 10px;
        background: var(--hf-dr-bg);
        box-shadow: var(--hf-dr-shadow);
        color: var(--hf-dr-text);
        font-size: 0.8125rem;
        overflow: hidden;
    }

    .hf-dr-body { display: flex; align-items: stretch; }

    .hf-dr-presets {
        display: flex;
        flex-direction: column;
        gap: 1px;
        width: 148px;
        padding: 8px;
        border-right: 1px solid var(--hf-dr-border);
        background: var(--hf-dr-surface);
    }
    .hf-dr-preset {
        padding: 6px 8px;
        border: 0;
        border-radius: 5px;
        background: transparent;
        color: var(--hf-dr-muted);
        font-family: inherit;
        font-size: 0.75rem;
        text-align: left;
        white-space: nowrap;
        cursor: pointer;
    }
    .hf-dr-preset:hover { background: var(--hf-dr-accent-soft); color: var(--hf-dr-text); }
    .hf-dr-preset:focus-visible { outline: 2px solid var(--hf-dr-accent); outline-offset: -2px; }

    .hf-dr-cal { flex: 1; min-width: 0; padding: 10px; }
    .hf-dr-cal-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 8px;
    }
    .hf-dr-month { font-size: 0.8125rem; font-weight: 600; }
    .hf-dr-nav {
        width: 24px;
        height: 24px;
        border: 1px solid var(--hf-dr-border);
        border-radius: 5px;
        background: var(--hf-dr-bg);
        color: var(--hf-dr-muted);
        font-family: inherit;
        font-size: 14px;
        line-height: 1;
        cursor: pointer;
    }
    .hf-dr-nav:hover { border-color: var(--hf-dr-accent); color: var(--hf-dr-text); }
    .hf-dr-nav:focus-visible { outline: 2px solid var(--hf-dr-accent); outline-offset: 1px; }

    .hf-dr-weekdays,
    .hf-dr-grid { display: grid; grid-template-columns: repeat(7, 1fr); }
    .hf-dr-weekdays {
        margin-bottom: 3px;
        color: var(--hf-dr-muted);
        font-size: 0.625rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-align: center;
        text-transform: uppercase;
    }
    .hf-dr-grid { gap: 1px 0; }

    .hf-dr-day {
        position: relative;
        height: 30px;
        border: 0;
        background: transparent;
        color: var(--hf-dr-text);
        font-family: inherit;
        font-size: 0.75rem;
        font-variant-numeric: tabular-nums;
        cursor: pointer;
    }
    .hf-dr-day:hover { background: var(--hf-dr-accent-soft); border-radius: 5px; }
    .hf-dr-day:focus-visible { outline: 2px solid var(--hf-dr-accent); outline-offset: -2px; }
    .hf-dr-day-outside { color: var(--hf-dr-muted); opacity: .55; }
    .hf-dr-day-today { font-weight: 700; text-decoration: underline; text-underline-offset: 3px; }
    .hf-dr-day-between { background: var(--hf-dr-accent-soft); }
    .hf-dr-day-start,
    .hf-dr-day-end {
        background: var(--hf-dr-accent);
        color: var(--hf-dr-on-accent);
        font-weight: 700;
        opacity: 1;
    }
    .hf-dr-day-start { border-radius: 5px 0 0 5px; }
    .hf-dr-day-end { border-radius: 0 5px 5px 0; }
    .hf-dr-day-start.hf-dr-day-end { border-radius: 5px; }
    .hf-dr-day-start:hover,
    .hf-dr-day-end:hover { background: var(--hf-dr-accent); }

    .hf-dr-times {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid var(--hf-dr-border);
    }
    .hf-dr-times label { display: flex; align-items: center; gap: 6px; min-width: 0; }
    .hf-dr-times span { color: var(--hf-dr-muted); font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; }
    .hf-dr-times input {
        flex: 1;
        min-width: 0;
        height: 27px;
        padding: 0 6px;
        border: 1px solid var(--hf-dr-border);
        border-radius: 5px;
        background: var(--hf-dr-bg);
        color: var(--hf-dr-text);
        font-family: inherit;
        font-size: 0.75rem;
    }
    .hf-dr-times input:disabled { opacity: .5; }
    .hf-dr-times input:focus-visible { outline: 2px solid var(--hf-dr-accent); outline-offset: 1px; }

    .hf-dr-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 8px 10px;
        border-top: 1px solid var(--hf-dr-border);
        background: var(--hf-dr-surface);
    }
    .hf-dr-foot-text {
        overflow: hidden;
        color: var(--hf-dr-muted);
        font-size: 0.6875rem;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .hf-dr-foot-actions { display: flex; gap: 6px; flex-shrink: 0; }
    .hf-dr-btn {
        height: 27px;
        padding: 0 11px;
        border: 1px solid var(--hf-dr-border);
        border-radius: 5px;
        background: var(--hf-dr-bg);
        color: var(--hf-dr-muted);
        font-family: inherit;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
    }
    .hf-dr-btn:hover { color: var(--hf-dr-text); border-color: var(--hf-dr-accent); }
    .hf-dr-btn:focus-visible { outline: 2px solid var(--hf-dr-accent); outline-offset: 1px; }
    .hf-dr-btn-primary {
        border-color: var(--hf-dr-accent);
        background: var(--hf-dr-accent);
        color: var(--hf-dr-on-accent);
    }
    .hf-dr-btn-primary:hover { color: var(--hf-dr-on-accent); filter: brightness(1.08); }
    .hf-dr-btn-primary:disabled { opacity: .5; cursor: not-allowed; filter: none; }

    @media (max-width: 575.98px) {
        .hf-dr-pop { width: calc(100vw - 16px); }
        .hf-dr-body { flex-direction: column; }
        .hf-dr-presets {
            width: 100%;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 4px;
            border-right: 0;
            border-bottom: 1px solid var(--hf-dr-border);
        }
    }
</style>
