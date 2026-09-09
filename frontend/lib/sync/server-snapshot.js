/**
 * A value-based fingerprint of one server render of the Sync page.
 *
 * The panel seeds `run`, `items` and `ignores` into state once and then owns
 * them, because a scan streams rows in by polling. `useState(initialRun)`
 * ignores every later prop, so `router.refresh()` re-ran the server component,
 * fetched fresh data, handed it over — and the screen kept showing the old
 * copy. The Refresh button spun through a real round trip and nothing changed.
 *
 * This is the `key` the page mounts the panel with, so a server render that
 * genuinely differs gives the panel fresh state and a fresh cursor, and one
 * that does not is a no-op. Resetting by key rather than syncing props into
 * state keeps the poll's ownership of the feed uncomplicated: there is only
 * ever one copy of it.
 *
 * Compared by value, never identity: the server builds new objects on every
 * render, so an identity check would remount the panel continuously and
 * restart its own scan.
 *
 * Items contribute their id plus the two fields that can move underneath one —
 * what happened to it, and what it became. Taking ids alone would have rested
 * on rows being immutable once written, which is true today only because
 * adopting starts a NEW run rather than rewriting the old one's rows. That is
 * a property of the backend, not of this screen, and it is cheaper to cover it
 * than to depend on it.
 */
export function serverSnapshot(run, items, ignores) {
  return JSON.stringify([
    run?.id ?? null,
    run?.status ?? null,
    run?.finished ?? null,
    run?.totals ?? null,
    (Array.isArray(items) ? items : []).map(
      (item) => `${item?.id}:${item?.action}:${item?.model_id}`,
    ),
    (Array.isArray(ignores) ? ignores : []).map(
      (ignore) => `${ignore?.resource_type}:${ignore?.resource_key}`,
    ),
  ]);
}
