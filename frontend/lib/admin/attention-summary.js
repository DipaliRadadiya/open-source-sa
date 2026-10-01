// Counts plus a few names for the dashboard; names and reasons come straight from the API.

/** How many names to print before falling back to "and N more". */
export const MAX_NAMES = 3;

/** The first non-empty line of stderr, truncated to `limit`. */
export function firstLine(text, limit = 80) {
  if (typeof text !== "string") return null;
  const line = text.split("\n").map((s) => s.trim()).find(Boolean);
  if (!line) return null;
  return line.length > limit ? `${line.slice(0, limit - 1)}…` : line;
}

/** The stderr line shared by a strict majority of failures, else null. */
export function dominantReason(groups = []) {
  const counts = new Map();
  let total = 0;

  for (const group of groups) {
    for (const occurrence of group.occurrences ?? []) {
      const line = firstLine(occurrence.error);
      if (!line) continue;
      total += 1;
      counts.set(line, (counts.get(line) ?? 0) + 1);
    }
  }

  if (!total) return null;
  const [line, count] = [...counts.entries()].sort((a, b) => b[1] - a[1])[0];
  return count / total > 0.5 ? line : null;
}

export function summarizeAttention({ checks = [], errorGroups = [] } = {}) {
  const failed = checks.filter((c) => c.status === "fail");
  const warnings = checks.filter((c) => c.status === "warn");
  // Counts occurrences, not groups.
  const occurrences = errorGroups.reduce((sum, g) => sum + (g.count ?? 0), 0);

  return {
    failed: { count: failed.length, names: failed.map((c) => c.title) },
    warnings: { count: warnings.length, names: warnings.map((c) => c.title) },
    failures: {
      count: occurrences,
      distinct: errorGroups.length,
      reason: dominantReason(errorGroups),
    },
    // What "view all" would cover: each distinct problem once.
    total: failed.length + warnings.length + errorGroups.length,
  };
}
