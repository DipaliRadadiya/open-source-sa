export const BULK_REASONS = ["has_applications", "has_processes", "failed"];
export const BULK_FIXES = ["has_applications", "has_processes"];

export function bulkDeleteOutcome(data = {}) {
  const succeeded = Array.isArray(data.succeeded) ? data.succeeded : [];
  const failures = {};
  for (const entry of Array.isArray(data.failed) ? data.failed : []) {
    if (entry?.id == null) continue;
    failures[entry.id] = {
      reason: BULK_REASONS.includes(entry.reason) ? entry.reason : "failed",
      reference: entry.reference ?? null,
    };
  }
  return { succeeded, failures };
}
