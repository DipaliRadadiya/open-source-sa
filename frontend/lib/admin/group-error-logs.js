/**
 * The error log grouped by fault: repeated occurrences collapse into one row
 * with a count.
 */

/**
 * Monolog writes ISO-8601, not the "DD-MM-YYYY HH:mm:ss" the rest of the API
 * sends, so parseApiDate() (strict on purpose) cannot be used here.
 */
export function parseLogDate(value) {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

/** "Illuminate\Database\QueryException" → "QueryException". */
export function shortException(name) {
  if (!name) return null;
  const parts = String(name).split("\\");
  return parts[parts.length - 1] || String(name);
}

/**
 * "api" or "operation". The `server-ops` channel carries both and they share
 * almost no fields: API exceptions have status/method/route/exception, failed
 * shell operations have feature/op/exit_code/stderr.
 */
export function entryKind(entry) {
  return entry.feature || entry.operation || entry.exit_code != null ? "operation" : "api";
}

/**
 * Group entries by "same problem": exception + method + route + status for API
 * errors, feature + operation + exit code for operations. Not by message
 * (it varies with ids and paths) or reference (unique per entry).
 *
 * Returns groups newest-last-seen first, occurrences in API order (newest first).
 */
export function groupErrorLogs(entries = []) {
  const groups = new Map();

  for (const entry of entries) {
    const kind = entryKind(entry);
    const key =
      kind === "operation"
        ? ["op", entry.feature ?? "?", entry.operation ?? "?", entry.exit_code ?? "?"].join(" ")
        : ["api", entry.exception ?? "?", entry.method ?? "?", entry.route ?? "?", entry.status ?? "?"].join(" ");
    const at = parseLogDate(entry.occurred_at);

    let group = groups.get(key);
    if (!group) {
      group = {
        key,
        kind,
        feature: entry.feature ?? null,
        operation: entry.operation ?? null,
        exitCode: entry.exit_code ?? null,
        exception: entry.exception ?? null,
        exceptionShort: shortException(entry.exception),
        method: entry.method ?? null,
        route: entry.route ?? null,
        status: entry.status ?? null,
        count: 0,
        first: null,
        last: null,
        occurrences: [],
      };
      groups.set(key, group);
    }

    group.count += 1;
    // `raw` is the entry as the API sent it (including passthrough fields),
    // for the raw view; the spread adds a parsed `at` that is not from the API.
    group.occurrences.push({ ...entry, at, raw: entry });
    // An unparseable timestamp still counts but cannot move first/last.
    if (at) {
      if (!group.first || at < group.first) group.first = at;
      if (!group.last || at > group.last) group.last = at;
    }
  }

  // Most recently seen first; groups with no usable timestamp sink to the bottom.
  return [...groups.values()].sort(
    (a, b) => (b.last?.getTime() ?? -Infinity) - (a.last?.getTime() ?? -Infinity),
  );
}

/** Plain-text search across the group's exception, route, method, status, feature and operation. */
export function groupMatches(group, query) {
  const needle = query.trim().toLowerCase();
  if (!needle) return true;
  return [
    group.exception,
    group.exceptionShort,
    group.route,
    group.method,
    group.status,
    group.feature,
    group.operation,
  ]
    .filter(Boolean)
    .some((field) => String(field).toLowerCase().includes(needle));
}
