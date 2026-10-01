// Readable text for a missing key: `set_ownership` → "Set ownership".
// Keys are often built from API values, so a new value can miss.
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

// Missing keys: logged in development, never thrown.
export function onError(error) {
  if (process.env.NODE_ENV !== "production") {
    console.warn(`[i18n] ${error.message}`);
  }
}
