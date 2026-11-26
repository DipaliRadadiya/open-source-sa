const UPPER = "ABCDEFGHJKLMNPQRSTUVWXYZ";
const LOWER = "abcdefghijkmnopqrstuvwxyz";
const DIGIT = "23456789";
const SYMBOL = "!@#$%*-_";
const ALPHABET = UPPER + LOWER + DIGIT + SYMBOL;

function pick(set) {
  const limit = Math.floor(0x100000000 / set.length) * set.length;
  const buf = new Uint32Array(1);
  let n;
  do {
    crypto.getRandomValues(buf);
    n = buf[0];
  } while (n >= limit);
  return set[n % set.length];
}

export function generatePassword(length = 20) {
  const required = [pick(UPPER), pick(LOWER), pick(DIGIT), pick(SYMBOL)];
  const chars = required.slice(0, Math.min(length, required.length));
  for (let i = chars.length; i < length; i += 1) chars.push(pick(ALPHABET));

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
