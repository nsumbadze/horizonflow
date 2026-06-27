/**
 * Persists live-flow view preferences (time range, filter, zoom, pan,
 * node layout) across reloads. localStorage access is wrapped so private
 * browsing / storage-disabled contexts degrade to session-only state.
 */
const KEY = 'horizonLiveFlowView';

export function loadViewState() {
    try {
        return JSON.parse(localStorage.getItem(KEY)) ?? {};
    } catch {
        return {};
    }
}

export function saveViewState(patch) {
    try {
        localStorage.setItem(KEY, JSON.stringify({ ...loadViewState(), ...patch }));
    } catch {
        // Storage unavailable — keep state for this session only.
    }
}
