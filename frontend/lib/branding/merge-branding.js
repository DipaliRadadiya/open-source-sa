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

// Only absolute http(s) URLs or root-relative paths are allowed; data:,
// javascript: and protocol-relative //host never reach an href or src.
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

// generatePalette returns null for an unreadable colour, which would drop the
// theme; checking here falls back to the default colour instead.
export function usableColor(value) {
  const trimmed = typeof value === "string" ? value.trim() : "";
  return Boolean(trimmed) && Boolean(toOklch(trimmed));
}

// Merged field by field: one unusable field must never discard the others.
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
