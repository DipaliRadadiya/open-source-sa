// Passwords for one-click installs. Uses Web Crypto, not Math.random. The
// alphabet drops look-alikes (0/O, 1/l/I) so retyped passwords survive.
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
 * A password that always passes validation. A uniform draw misses a digit ~7%
 * of the time, so one character of each class is placed first, the rest drawn
 * from the full alphabet, and the result shuffled.
 */
export function generatePassword(length = 20) {
  const required = [pick(UPPER), pick(LOWER), pick(DIGIT), pick(SYMBOL)];
  const chars = required.slice(0, Math.min(length, required.length));
  for (let i = chars.length; i < length; i += 1) chars.push(pick(ALPHABET));

  // Fisher-Yates, so the guaranteed characters are not a predictable prefix.
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
