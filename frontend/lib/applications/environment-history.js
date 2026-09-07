/**
 * How a history row is read, kept out of the component so it can be tested
 * without a DOM.
 *
 * Every branch here exists because two rows that look similar mean different
 * things, and collapsing them would be the lie the screen exists to prevent.
 */

/**
 * Why a row cannot be restored — or null when it can.
 *
 * Three states, not two. "Never had a backup" is a first save: there was no
 * previous version, so nothing was lost. "Pruned" is a version that existed and
 * was deleted to stay inside the retention limit. Rendering both as a disabled
 * button would tell someone their first save had a version they could have had.
 */
export function unrestorableReason(entry) {
  if (entry?.restorable) return null;
  if (!entry?.backup) return "first";

  return "pruned";
}

/**
 * The keys a change touched, as a list.
 *
 * The backend stores them as one comma-joined string, and "—" is its own way of
 * saying a save changed nothing at all — someone pressed Save on an unedited
 * file, or edited only comments and whitespace. That is worth showing as its
 * own state rather than as an empty row.
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
 * Who to credit.
 *
 * A null user is not a missing field: the panel writes system actions with no
 * user on purpose rather than blaming an admin who was not there. So `is_system`
 * is asked first, and a null user without that flag is a deleted account — a
 * third answer, and not one to render as "system".
 */
export function actorOf(entry) {
  if (entry?.is_system) return { kind: "system" };
  if (entry?.user?.username) return { kind: "user", username: entry.user.username };

  return { kind: "unknown" };
}
