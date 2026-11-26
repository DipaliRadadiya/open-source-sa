// Monolog writes ISO-8601, so parseApiDate() can't read it.
export function parseLogDate(value) {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function shortException(name) {
  if (!name) return null;
  const parts = String(name).split("\\");
  return parts[parts.length - 1] || String(name);
}

export function entryKind(entry) {
  return entry.feature || entry.operation || entry.exit_code != null ? "operation" : "api";
}

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
        featureLabel: entry.feature_label ?? null,
        operationLabel: entry.operation_label ?? null,
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
    group.occurrences.push({ ...entry, at, raw: entry });
    if (at) {
      if (!group.first || at < group.first) group.first = at;
      if (!group.last || at > group.last) group.last = at;
    }
  }

  return [...groups.values()].sort(
    (a, b) => (b.last?.getTime() ?? -Infinity) - (a.last?.getTime() ?? -Infinity),
  );
}

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
    group.featureLabel,
    group.operationLabel,
  ]
    .filter(Boolean)
    .some((field) => String(field).toLowerCase().includes(needle));
}
