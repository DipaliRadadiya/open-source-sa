/**
 * How a re-read tail relates to the one before it: how many lines are new at
 * the end, and how many fell off the start. The new buffer starts somewhere
 * inside the old one — find where, by the first offset at which the rest of
 * the old buffer matches the start of the new.
 */
export function appended(before, after) {
  for (let dropped = 0; dropped < before.length; dropped += 1) {
    const overlap = before.length - dropped;
    if (overlap > after.length) continue;
    let same = true;
    for (let i = 0; i < overlap; i += 1) {
      if (before[dropped + i] !== after[i]) {
        same = false;
        break;
      }
    }
    if (same) return { added: after.length - overlap, dropped };
  }
  // Nothing in common: everything on screen is new.
  return { added: after.length, dropped: before.length };
}
