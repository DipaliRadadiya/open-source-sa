// Server-only modules the SSR fetchers import, for node:test.
export async function cookies() {
  return { toString: () => "session=fixture" };
}
export async function serverLocale() {
  return "en";
}
export async function getApplication() {
  return { application: null };
}
export async function signedOutPath() {
  return "/login";
}
export function redirect(path) {
  throw new Error(`redirect ${path}`);
}
