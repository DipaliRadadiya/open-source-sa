export function getMessageFallback({ key }) {
  const last = String(key).split(".").pop() ?? "";
  const words = last
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .replace(/[._-]+/g, " ")
    .trim()
    .toLowerCase();

  if (!words) return String(key);

  return words.charAt(0).toUpperCase() + words.slice(1);
}

export function onError(error) {
  if (process.env.NODE_ENV !== "production") {
    console.warn(`[i18n] ${error.message}`);
  }
}
