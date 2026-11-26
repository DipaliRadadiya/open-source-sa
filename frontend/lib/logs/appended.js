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
  return { added: after.length, dropped: before.length };
}
