// The panel's `key`: it seeds state once, so a different render must remount it.
// Compared by value, never identity: every render builds new objects.
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
