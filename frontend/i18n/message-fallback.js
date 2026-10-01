/**
 * What the panel shows when a translation key does not resolve.
 *
 * next-intl's default is the key itself, which looks broken mid-sentence. Many
 * call sites build keys from API values (`t(\`status.${row.status}\`)`), so a
 * new backend value would ship its identifier to the screen; one fallback covers
 * every such call site, including future ones.
 */

/**
 * The last key segment, made readable: `set_ownership` → "Set ownership".
 * The namespace adds nothing for the reader. Only the first letter is
 * capitalised; title-casing snake_case reads like a proper noun.
 */
export function getMessageFallback({ key }) {
  const last = String(key).split(".").pop() ?? "";
  const words = last
    // API values are snake_case; our own keys are camelCase and need splitting too.
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .replace(/[._-]+/g, " ")
    .trim()
    .toLowerCase();

  if (!words) return String(key);

  return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * A miss is a bug in our message files: logged, never thrown, since the strings
 * most likely to be missing describe a failure the user is already facing.
 * Silent in production. `check-i18n.mjs` catches literal keys; only dynamic ones
 * reach this.
 */
export function onError(error) {
  if (process.env.NODE_ENV !== "production") {
    console.warn(`[i18n] ${error.message}`);
  }
}
