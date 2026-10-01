/** How a `.env` history row is read; kept out of the component for testing. */

/**
 * Why a row cannot be restored, or null when it can. A first save (no previous
 * version) differs from one pruned by the retention limit.
 */
export function unrestorableReason(entry) {
  if (entry?.restorable) return null;
  if (!entry?.backup) return "first";

  return "pruned";
}

/**
 * The keys a change touched. The backend stores a comma-joined string; "—"
 * means the save changed no keys (unedited, or comments/whitespace only).
 */
export function changedKeys(entry) {
  const raw = (entry?.keys ?? "").trim();

  if (raw === "" || raw === "—") return [];

  return raw
    .split(",")
    .map((key) => key.trim())
    .filter(Boolean);
}

/**
 * Who to credit. `is_system` is checked first; a null user without it is a
 * deleted account, not a system action.
 */
export function actorOf(entry) {
  if (entry?.is_system) return { kind: "system" };
  if (entry?.user?.username) return { kind: "user", username: entry.user.username };

  return { kind: "unknown" };
}
