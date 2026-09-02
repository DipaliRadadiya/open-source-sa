import { getRequestConfig } from "next-intl/server";
import { cookies, headers } from "next/headers";
import { locales, defaultLocale } from "./routing";
import { getMessageFallback, onError } from "./message-fallback";

const COOKIE = "NEXT_LOCALE";

// Resolve locale from the NEXT_LOCALE cookie, then Accept-Language, then the
// default — no URL prefix, no middleware. Cookie is the source of truth and is
// set by the locale switcher (lib/i18n/locale-actions.js).
async function resolveLocale() {
  const cookieStore = await cookies();
  const fromCookie = cookieStore.get(COOKIE)?.value;
  if (fromCookie && locales.includes(fromCookie)) return fromCookie;

  const accept = (await headers()).get("accept-language") || "";
  const preferred = accept.split(",")[0]?.trim().slice(0, 2).toLowerCase();
  if (preferred && locales.includes(preferred)) return preferred;

  return defaultLocale;
}

// Deep-merge a (partial) translation over the English base so any missing key
// falls back to English per-key — a locale file never has to be complete.
function deepMerge(base, over) {
  const out = { ...base };
  for (const key of Object.keys(over || {})) {
    const b = base?.[key];
    const o = over[key];
    out[key] =
      b && o && typeof b === "object" && typeof o === "object" && !Array.isArray(o)
        ? deepMerge(b, o)
        : o;
  }
  return out;
}

export default getRequestConfig(async () => {
  const locale = await resolveLocale();

  const en = (await import("../messages/en.json")).default;
  let messages = en;
  if (locale !== defaultLocale) {
    try {
      const localized = (await import(`../messages/${locale}.json`)).default;
      messages = deepMerge(en, localized);
    } catch {
      // No translation file for this locale yet → stay on English.
      messages = en;
    }
  }

  // Explicit timeZone: without it SSR formats in the Node process' zone and the
  // client re-formats in the browser's, which hydrates differently. This panel
  // monitors one machine, so that machine's zone is also the right one to show.
  const timeZone =
    process.env.NEXT_PUBLIC_DISPLAY_TIMEZONE ||
    Intl.DateTimeFormat().resolvedOptions().timeZone ||
    "UTC";

  return { locale, messages, timeZone, getMessageFallback, onError };
});
