/**
 * The error log, grouped the way Sentry and Telescope group theirs: repeated
 * occurrences of one fault collapse into a single row carrying a count.
 *
 * A flat feed is the wrong shape for this data. One broken endpoint hit by a
 * polling dashboard writes hundreds of identical lines, and an ungrouped list
 * shows the reader those hundreds instead of the three distinct things that are
 * actually wrong.
 */

/**
 * Monolog writes ISO-8601 ("2026-08-14T10:15:03.123456+00:00"), not the
 * "DD-MM-YYYY HH:mm:ss" the rest of the API sends, so parseApiDate() — which is
 * strict about that format on purpose — returns null here. Date handles ISO in
 * every engine, which is exactly why the other helper has to exist and this one
 * can be three lines.
 */
export function parseLogDate(value) {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * "Illuminate\Database\QueryException" → "QueryException".
 *
 * The namespace is noise in a list — every entry is a Laravel or PHP class and
 * the last segment is the part that differs. The full name is still shown on
 * the group, just not as the headline.
 */
export function shortException(name) {
  if (!name) return null;
  const parts = String(name).split("\\");
  return parts[parts.length - 1] || String(name);
}

/**
 * Which of the two things an entry is.
 *
 * Since the backend unified the API-error and server-operation logs into one
 * `server-ops` channel, this endpoint returns both, and they share almost no
 * fields. An API exception carries status/method/route/exception and no
 * feature; a failed shell operation carries feature/op/exit_code/stderr and no
 * route. Telling them apart is the first thing anything downstream has to do —
 * grouping a run of failed operations by "exception + route + status" would key
 * every one of them on `? ? ? ?` and collapse unrelated failures into one row.
 */
export function entryKind(entry) {
  return entry.feature || entry.operation || entry.exit_code != null ? "operation" : "api";
}

/**
 * Group entries by what makes two failures "the same problem".
 *
 * For an API exception that is the exception class, the route and the status.
 * For a server operation it is the feature, the operation and the exit code —
 * `php`/`install` failing with 100 is one problem, and `php`/`install` failing
 * with 1 is a different one.
 *
 * Not grouped by message (still near-constant) and not by reference: it is
 * unique per entry, so grouping on it would produce one group per occurrence —
 * the flat list this function exists to avoid.
 *
 * Returns groups newest-last-seen first, each with its occurrences in the same
 * order the API sent them (newest first).
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
    // `raw` is the entry exactly as the API sent it, kept beside the grouped
    // copy so the raw view shows the response rather than our shape — the
    // spread above adds a parsed `at` Date that never came from the backend.
    // It also carries the schema's passthrough fields, so anything the backend
    // starts sending appears there without a change here.
    group.occurrences.push({ ...entry, at, raw: entry });
    // An entry with an unparseable timestamp still counts; it just cannot move
    // the group's first/last, which would otherwise become Invalid Date.
    if (at) {
      if (!group.first || at < group.first) group.first = at;
      if (!group.last || at > group.last) group.last = at;
    }
  }

  // Most recently seen first: the thing breaking right now is the thing being
  // looked for. Groups with no usable timestamp sink to the bottom.
  return [...groups.values()].sort(
    (a, b) => (b.last?.getTime() ?? -Infinity) - (a.last?.getTime() ?? -Infinity),
  );
}

/**
 * Does this group match a plain-text search? Matches the exception (short and
 * full), the route and the method for an API failure, and the feature and
 * operation for a server operation — so "QueryException", "databases" and
 * "php" all find the row they should.
 */
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
