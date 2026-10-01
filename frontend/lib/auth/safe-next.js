// Security: anything but a single-leading-slash in-panel path is dropped, never
// sanitised, to avoid open redirects (`//host` is protocol-relative). Apply it
// to values read back from sessionStorage too. No imports, so any module can share it.
export function safeNext(value) {
  if (typeof value !== "string" || !value.startsWith("/") || value.startsWith("//")) return null;
  // Returning to a signed-out screen after signing in is a loop.
  if (/^\/(login|register|setup)\b/.test(value)) return null;

  return value;
}
