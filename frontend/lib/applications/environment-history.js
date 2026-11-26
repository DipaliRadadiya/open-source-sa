export function unrestorableReason(entry) {
  if (entry?.restorable) return null;
  if (!entry?.backup) return entry?.safety_copy === false ? "noCopy" : "first";

  return "pruned";
}

export function changedKeys(entry) {
  const raw = (entry?.keys ?? "").trim();

  if (raw === "" || raw === "—") return [];

  return raw
    .split(",")
    .map((key) => key.trim())
    .filter(Boolean);
}

export function actorOf(entry) {
  if (entry?.is_system) return { kind: "system" };
  if (entry?.user?.username) return { kind: "user", username: entry.user.username };

  return { kind: "unknown" };
}
