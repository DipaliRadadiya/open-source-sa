/**
 * The message to show a person when a request fails.
 *
 * The API usually sends a written sentence, and that's better than anything the
 * frontend could compose because it knows what actually went wrong. But it
 * sometimes sends an untranslated lookup key instead — `errors/php.operation_failed`
 * from a 500 — and putting that in a toast tells the reader nothing while looking
 * like the panel is broken. Key-shaped strings are therefore ignored in favour of
 * our own copy.
 *
 * A key has no spaces and is built from slashes/dots; any real sentence has spaces.
 */
export function apiMessage(error, fallback) {
  const message = error?.response?.data?.message;
  if (typeof message !== "string") return fallback;

  const trimmed = message.trim();
  if (!trimmed) return fallback;
  if (!/\s/.test(trimmed) && /[/.]/.test(trimmed)) return fallback;

  return trimmed;
}
