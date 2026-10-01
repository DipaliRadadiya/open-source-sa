/** How a `.env` history row is read; kept out of the component for testing. */

// A first save (no previous version) differs from one pruned by retention.
export function unrestorableReason(entry) {
  if (entry?.restorable) return null;
  if (!entry?.backup) return "first";

  return "pruned";
}

// The backend stores a comma-joined string; "—" means no keys changed.
export function changedKeys(entry) {
  const raw = (entry?.keys ?? "").trim();

  if (raw === "" || raw === "—") return [];

  return raw
    .split(",")
    .map((key) => key.trim())
    .filter(Boolean);
}

// `is_system` first; a null user without it is a deleted account.
export function actorOf(entry) {
  if (entry?.is_system) return { kind: "system" };
  if (entry?.user?.username) return { kind: "user", username: entry.user.username };

  return { kind: "unknown" };
}
