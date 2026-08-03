// Ambiguous characters (l, 1, 0, O) are left out on purpose — these values get
// retyped by hand into config files, and read aloud over calls.
const SAFE = "abcdefghijkmnopqrstuvwxyz23456789";
const PASSWORD_EXTRA = "ABCDEFGHJKLMNPQRSTUVWXYZ!@#%^*_-+=";

function pick(alphabet, length) {
  const bytes = new Uint8Array(length);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => alphabet[b % alphabet.length]).join("");
}

/**
 * A username nobody can guess from the database name.
 *
 * `wp_main` for the database and `wp_main` for the user means half the
 * credential is public the moment anyone learns the database name. Random also
 * sidesteps collisions: a user is unique per SERVER, while database names are
 * only unique per project.
 */
export function randomUsername() {
  return `db_${pick(SAFE, 10)}`;
}

/**
 * A password worth having. Only offered, never forced — the field stays
 * editable, and creating a database lets the API generate one instead.
 */
export function randomPassword() {
  return pick(SAFE + PASSWORD_EXTRA, 24);
}
