import { converter } from "culori";

export const DEFAULT_BRANDING = {
  name: "ServerAvatar",
  logo: "https://app.serveravatar.com/logo/SaLogoDark.png",
  logo_dark: "https://app.serveravatar.com/logo/dark-logo.png",
  icon: "https://app.serveravatar.com/logo/logo-sm.png",
  icon_dark: "https://app.serveravatar.com/logo/dark-logo-sm.png",
  favicon: "https://app.serveravatar.com/logo/logo-sm.png",
  primary_color: "#076aff",
};

export const BRANDING_ASSET_FIELDS = [
  "logo",
  "logo_dark",
  "icon",
  "icon_dark",
  "favicon",
];

const toOklch = converter("oklch");

// An asset is either an absolute http(s) URL or a root-relative path served by
// the panel itself. Anything else — a bare word, a data:/javascript: scheme, a
// protocol-relative //host — never reaches a <link href> or an <img src>.
export function usableAsset(value) {
  const trimmed = typeof value === "string" ? value.trim() : "";
  if (!trimmed) return false;
  if (trimmed.startsWith("//")) return false;
  if (trimmed.startsWith("/")) return true;

  try {
    const { protocol } = new URL(trimmed);
    return protocol === "http:" || protocol === "https:";
  } catch {
    return false;
  }
}

// generatePalette returns null for a colour it cannot read, which would drop the
// theme entirely. Checking here means an unreadable colour falls back to the
// default brand colour rather than to no brand colour at all.
export function usableColor(value) {
  const trimmed = typeof value === "string" ? value.trim() : "";
  return Boolean(trimmed) && Boolean(toOklch(trimmed));
}

// Branding wins wherever it carries a usable value; the defaults only fill the
// gaps, field by field. One unusable field must never discard the others.
export function mergeBranding(branding) {
  const merged = { ...DEFAULT_BRANDING };
  if (!branding || typeof branding !== "object") return merged;

  if (typeof branding.name === "string" && branding.name.trim()) {
    merged.name = branding.name.trim();
  }

  if (usableColor(branding.primary_color)) {
    merged.primary_color = branding.primary_color.trim();
  }

  for (const field of BRANDING_ASSET_FIELDS) {
    if (usableAsset(branding[field])) merged[field] = branding[field].trim();
  }

  return merged;
}
