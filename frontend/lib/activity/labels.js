// Turn a raw activity key like "user.created" into a readable "User Created".
export function humanizeActivity(key) {
  if (!key) return "";
  return key
    .replace(/[._]/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

// Semantic badge variant for an activity verb (green for additions, red for
// removals/failures, neutral otherwise).
export function actionBadgeVariant(action) {
  if (!action) return "secondary";
  const a = action.toLowerCase();
  if (
    a.includes("fail") ||
    a.startsWith("delete") ||
    a.includes("removed") ||
    a.includes("disabled")
  ) {
    return "destructive";
  }
  if (
    a.startsWith("create") ||
    a.includes("added") ||
    a.includes("registered") ||
    a.includes("enabled") ||
    a.includes("_set")
  ) {
    return "success";
  }
  return "secondary";
}
