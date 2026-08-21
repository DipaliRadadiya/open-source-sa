// A strong password for one-click installs (WordPress admin, database users).
// Uses the Web Crypto RNG, not Math.random — this ends up guarding a live site.
// The alphabet drops look-alikes (0/O, 1/l/I) so a password read off the screen
// and retyped elsewhere survives the trip.
const UPPER = "ABCDEFGHJKLMNPQRSTUVWXYZ";
const LOWER = "abcdefghijkmnopqrstuvwxyz";
const DIGIT = "23456789";
const SYMBOL = "!@#$%*-_";
const ALPHABET = UPPER + LOWER + DIGIT + SYMBOL;

/** A uniform index into `set`, rejecting the biased tail of the RNG range. */
function pick(set) {
  // 2^32 is not a multiple of most set sizes, so the low indices would come up
  // slightly more often. Redraw the values that fall in the remainder.
  const limit = Math.floor(0x100000000 / set.length) * set.length;
  const buf = new Uint32Array(1);
  let n;
  do {
    crypto.getRandomValues(buf);
    n = buf[0];
  } while (n >= limit);
  return set[n % set.length];
}

/**
 * A password that always satisfies the rules we validate it against.
 *
 * Drawing every character uniformly from one alphabet does NOT guarantee one of
 * each class, and the digits are the thin end: only 8 of the 65 characters are
 * digits, so a 20-character draw missed them (57/65)^20 = **7.2%** of the time.
 * Measured over 200k runs: 7.24%.
 *
 * That is not a rare edge. Every schema this feeds requires a digit, so roughly
 * one in fourteen "Generate" clicks produced a password the panel then refused
 * — "Include a number" pointing at a field the user never typed in.
 *
 * So one character of each required class is placed first, the rest are drawn
 * from the full alphabet, and the result is shuffled so the guaranteed
 * characters do not always sit at the front.
 */
export function generatePassword(length = 20) {
  const required = [pick(UPPER), pick(LOWER), pick(DIGIT), pick(SYMBOL)];
  const chars = required.slice(0, Math.min(length, required.length));
  for (let i = chars.length; i < length; i += 1) chars.push(pick(ALPHABET));

  // Fisher-Yates, so the class order is not "upper, lower, digit, symbol, …" —
  // a predictable prefix is a smaller search space for anyone guessing.
  for (let i = chars.length - 1; i > 0; i -= 1) {
    const limit = Math.floor(0x100000000 / (i + 1)) * (i + 1);
    const buf = new Uint32Array(1);
    let n;
    do {
      crypto.getRandomValues(buf);
      n = buf[0];
    } while (n >= limit);
    const j = n % (i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }

  return chars.join("");
}
