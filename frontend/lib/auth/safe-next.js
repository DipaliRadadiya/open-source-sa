/**
 * A `?next=` value that is safe to navigate to after signing in.
 *
 * Deliberately its own module with no imports, so both the recorder that
 * writes the value and the login form that spends it can share one definition
 * of "safe" without either dragging the other's dependencies along.
 *
 * Dropped rather than sanitised. A value that is not a single-leading-slash
 * in-panel path is discarded whole, because the alternative — trimming an
 * absolute URL down to something that looks local — is how open redirects
 * survive review. `//host` is rejected too: the browser reads that as
 * protocol-relative and leaves the site entirely.
 *
 * Applied on the way OUT as well as the way in. The path is recorded into
 * sessionStorage by a component inside the signed-in shell, and sessionStorage
 * is writable by anything running on the origin — so a value read back out of
 * it has exactly as much authority as one typed into the address bar, which is
 * none.
 */
export function safeNext(value) {
  if (typeof value !== "string" || !value.startsWith("/") || value.startsWith("//")) return null;
  // Returning to a signed-out screen after signing in is a loop.
  if (/^\/(login|register|setup)\b/.test(value)) return null;

  return value;
}
