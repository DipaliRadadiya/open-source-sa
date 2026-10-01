// Lines added at the end and dropped from the start between two tails, found by the first
// offset where the rest of the old buffer matches the start of the new.
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
