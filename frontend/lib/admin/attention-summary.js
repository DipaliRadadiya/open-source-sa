/**
 * Reduce the diagnostics to the three sentences a dashboard should carry.
 *
 * The dashboard listed every failed check, every command failure and every
 * warning — seventeen rows of shell output on a page whose job is to say
 * whether anything is urgent. The full report belongs on System Health and the
 * Error Log; here we count them and name a few, and the reader clicks through
 * for the rest.
 *
 * Nothing is inferred. The names are the check titles the API sent, and the
 * "most report" line is a quotation of the most common stderr, offered only
 * when it genuinely accounts for most of the failures.
 */

/** How many names to print before falling back to "and N more". */
export const MAX_NAMES = 3;

/**
 * The first line of a command's stderr, short enough to sit in a summary.
 *
 * stderr is multi-line and the first line is the one that says what happened;
 * everything after it is usually a usage banner or a stack.
 */
export function firstLine(text, limit = 80) {
  if (typeof text !== "string") return null;
  const line = text.split("\n").map((s) => s.trim()).find(Boolean);
  if (!line) return null;
  return line.length > limit ? `${line.slice(0, limit - 1)}…` : line;
}

/**
 * The stderr shared by most of the failures, or null when they disagree.
 *
 * A dashboard that says "most report X" when X is a third of them is worse
 * than one that says nothing, so this returns null below a majority. On this
 * panel every failure is the same sudo error, which is exactly the case worth
 * saying out loud — one cause, eleven symptoms.
 */
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
  // Occurrences, not groups: "11 recent command failures" is what happened,
  // and three of them being the same command is what the reason line is for.
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
