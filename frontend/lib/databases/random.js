const SAFE = "abcdefghijkmnopqrstuvwxyz23456789";
const PASSWORD_EXTRA = "ABCDEFGHJKLMNPQRSTUVWXYZ!@#%^*_-+";

function pick(alphabet, length) {
  const bytes = new Uint8Array(length);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => alphabet[b % alphabet.length]).join("");
}

export function randomUsername() {
  return `db_${pick(SAFE, 10)}`;
}

export function randomPassword() {
  return pick(SAFE + PASSWORD_EXTRA, 24);
}
