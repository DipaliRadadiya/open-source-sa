// Only CONSECUTIVE identical entries merge, so the feed keeps its order.
const sameEvent = (a, b) =>
  a.action === b.action &&
  a.type === b.type &&
  (a.user?.id ?? null) === (b.user?.id ?? null) &&
  Boolean(a.is_system) === Boolean(b.is_system);

const identity = (entry) =>
  [entry.type, entry.action, entry.user?.id ?? "", Boolean(entry.is_system)].join("|");

// `mergeAcross` actions merge per actor even when not adjacent, at that actor's latest position.
export function collapseRepeats(entries = [], { max = Infinity, mergeAcross = [] } = {}) {
  const groups = [];
  const merged = new Map();

  for (const entry of entries) {
    if (mergeAcross.includes(entry.action)) {
      const existing = merged.get(identity(entry));
      if (existing) {
        existing.count += 1;
        existing.oldest = entry;
        continue;
      }
      const group = { key: String(entry.id), newest: entry, oldest: entry, count: 1 };
      merged.set(identity(entry), group);
      groups.push(group);
      continue;
    }

    const last = groups[groups.length - 1];
    if (last && sameEvent(last.newest, entry)) {
      last.count += 1;
      // Entries arrive newest first, so each further match is older.
      last.oldest = entry;
      continue;
    }
    groups.push({ key: String(entry.id), newest: entry, oldest: entry, count: 1 });
  }

  return groups.slice(0, max);
}
