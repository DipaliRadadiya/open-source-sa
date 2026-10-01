/**
 * A value-based fingerprint of one server render of the Sync page.
 *
 * Used as the panel's `key`: the panel seeds `run`, `items` and `ignores` into
 * state once (the poll owns them after), so a genuinely different server render
 * must remount it to show fresh data after `router.refresh()`.
 *
 * Compared by value, never identity: the server builds new objects on every
 * render, which would remount continuously. Items contribute their id plus the
 * fields that can change (`action`, `model_id`), rather than relying on rows
 * being immutable.
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
