/**
 * What the panel shows when a translation key does not resolve.
 *
 * next-intl's own fallback is the key itself, so a missing message renders
 * `applications.details.steps.set_ownership` in the middle of a sentence. That
 * is not a translation problem the reader can do anything with — it looks like
 * the panel is broken.
 *
 * It matters here more than in most apps because 151 places in this codebase
 * build their key from a value the API supplied — `t(\`status.${row.status}\`)`
 * and its kin. Every one of those is a key we cannot know in advance, so the
 * day the backend adds a status we have no wording for, the identifier is what
 * ships to the screen. That is exactly how a deploy failure came to read
 * `failed at "verify"`.
 *
 * Guarding all 151 call sites with `t.has()` would protect only the ones
 * someone remembered, and only until the next one is written. One fallback
 * covers them all, including the ones not written yet.
 */

/**
 * The last segment of the key, made readable: `set_ownership` → "Set ownership".
 *
 * The last segment rather than the whole path because the namespace is
 * scaffolding — `applications.details.steps` says nothing to a reader that the
 * surrounding sentence has not already said. Underscores and dots become
 * spaces, and only the first letter is capitalised: these identifiers are
 * snake_case, and title-casing every word turns "seed env" into "Seed Env",
 * which reads like a proper noun.
 */
export function getMessageFallback({ key }) {
  const last = String(key).split(".").pop() ?? "";
  const words = last
    // Values from the API are snake_case; keys we write ourselves are
    // camelCase, and `thisKeyDoesNotExist` unsplit reads worse than the
    // identifier it replaced.
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .replace(/[._-]+/g, " ")
    .trim()
    .toLowerCase();

  if (!words) return String(key);

  return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * A miss is a bug in our message files, so it is logged rather than swallowed —
 * but never thrown. A page that renders slightly wrong copy is recoverable; a
 * page that crashes on a missing string is not, and the strings most likely to
 * be missing are the ones describing a failure the user is already dealing
 * with.
 *
 * Silent in production because the reader cannot act on it and the console is
 * not ours to fill. `check-i18n.mjs` catches every *literal* key before it
 * ships; this is only ever reached by the dynamic ones it cannot see.
 */
export function onError(error) {
  if (process.env.NODE_ENV !== "production") {
    console.warn(`[i18n] ${error.message}`);
  }
}
