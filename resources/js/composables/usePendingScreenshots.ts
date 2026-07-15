import { ref } from "vue";

/**
 * Client-side registry of pending (snapped but not yet attached) screenshot
 * ids. Module-scoped so the count survives dialog open/close and page visits
 * within the SPA session.
 *
 * Known limitation (v1): a full page reload loses this in-memory list — the
 * uploaded pending files still exist server-side (and are cleaned per the
 * package's pending lifecycle) but the badge/strip won't show them, as there
 * is no list-pending endpoint in the v1 contract.
 */
const pendingIds = ref<string[]>([]);

export function usePendingScreenshots() {
    function add(id: string): void {
        if (!pendingIds.value.includes(id)) {
            pendingIds.value.push(id);
        }
    }

    function remove(id: string): void {
        pendingIds.value = pendingIds.value.filter((pending) => pending !== id);
    }

    function removeMany(ids: string[]): void {
        pendingIds.value = pendingIds.value.filter((pending) => !ids.includes(pending));
    }

    return { pendingIds, add, remove, removeMany };
}
