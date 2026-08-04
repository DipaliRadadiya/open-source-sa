// A strong password for one-click installs (WordPress admin, database users).
// Uses the Web Crypto RNG, not Math.random — this ends up guarding a live site.
// The alphabet drops look-alikes (0/O, 1/l/I) so a password read off the screen
// and retyped elsewhere survives the trip.
const ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%*-_";

export function generatePassword(length = 20) {
  const values = new Uint32Array(length);
  crypto.getRandomValues(values);
  let out = "";
  for (let i = 0; i < length; i += 1) out += ALPHABET[values[i] % ALPHABET.length];
  return out;
}
