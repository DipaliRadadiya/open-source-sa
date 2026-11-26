export const FALLBACK_TEMPORARY_SUFFIX = "nip.io";

const MAX_LABEL = 63;

export function toDomainLabel(name) {
  const label = String(name ?? "")
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, MAX_LABEL);

  return label.replace(/-+$/g, "");
}

export function ipToLabel(ip) {
  const trimmed = String(ip ?? "").trim();
  return /^\d{1,3}(\.\d{1,3}){3}$/.test(trimmed) ? trimmed.replace(/\./g, "-") : "";
}

export const DEFAULT_TEMPORARY_LABEL = "site";

export function preferredSuffix(suffixes) {
  const first = (Array.isArray(suffixes) ? suffixes : []).find(
    (suffix) => typeof suffix === "string" && suffix.trim(),
  );
  return first?.trim() || FALLBACK_TEMPORARY_SUFFIX;
}

export function temporaryDomain(
  name,
  ip,
  { fallbackLabel = DEFAULT_TEMPORARY_LABEL, suffixes } = {},
) {
  const host = ipToLabel(ip);
  if (!host) return null;

  const label = toDomainLabel(name) || toDomainLabel(fallbackLabel);
  if (!label) return null;

  return `${label}.${host}.${preferredSuffix(suffixes)}`;
}

// Without an IPv4 the toggle is hidden, so start on own or the field stays empty.
export function initialDomainMode({ serverIp } = {}) {
  return ipToLabel(serverIp) ? "temporary" : "own";
}
