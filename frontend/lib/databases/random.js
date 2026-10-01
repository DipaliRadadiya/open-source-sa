// Ambiguous characters (l, 1, 0, O) are left out: these get retyped by hand.
const SAFE = "abcdefghijkmnopqrstuvwxyz23456789";
// No `=`: PrestaShop's installer and other `key=value` readers cut at the first `=`.
const PASSWORD_EXTRA = "ABCDEFGHJKLMNPQRSTUVWXYZ!@#%^*_-+";

function pick(alphabet, length) {
  const bytes = new Uint8Array(length);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => alphabet[b % alphabet.length]).join("");
}

/**
 * A random username, so it cannot be guessed from the database name and does
 * not collide (users are unique per server).
 */
export function randomUsername() {
  return `db_${pick(SAFE, 10)}`;
}

/** A suggested password; the field stays editable. */
export function randomPassword() {
  return pick(SAFE + PASSWORD_EXTRA, 24);
}
