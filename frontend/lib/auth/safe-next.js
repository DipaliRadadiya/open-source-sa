// Only same-site paths starting with a single /, to prevent open redirects.
export function safeNext(value) {
  if (typeof value !== "string" || !value.startsWith("/") || value.startsWith("//")) return null;
  if (/^\/(login|register|setup)\b/.test(value)) return null;

  return value;
}
