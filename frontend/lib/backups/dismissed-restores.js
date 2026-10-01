// A cookie so the server can leave dismissed restores out; hiding them after
// hydration would flash the banner on every reload.
export const DISMISSED_RESTORES_COOKIE = "sv_dismissed_restores";

const KEEP = 20;

export function parseDismissedRestores(value) {
  return String(value ?? "")
    .split(",")
    .map(Number)
    .filter((id) => Number.isInteger(id) && id > 0);
}

export function rememberDismissedRestore(id) {
  if (typeof document === "undefined" || !id) return;
  const current = parseDismissedRestores(
    document.cookie
      .split("; ")
      .find((part) => part.startsWith(`${DISMISSED_RESTORES_COOKIE}=`))
      ?.split("=")[1],
  );
  const next = [...current.filter((known) => known !== id), id].slice(-KEEP);
  document.cookie = `${DISMISSED_RESTORES_COOKIE}=${next.join(",")}; path=/; max-age=${7 * 24 * 3600}; samesite=lax`;
}
