import { getRequestConfig } from "next-intl/server";
import { cookies, headers } from "next/headers";
import { locales, defaultLocale } from "./routing";
import { getMessageFallback, onError } from "./message-fallback";

const COOKIE = "NEXT_LOCALE";

async function resolveLocale() {
  const cookieStore = await cookies();
  const fromCookie = cookieStore.get(COOKIE)?.value;
  if (fromCookie && locales.includes(fromCookie)) return fromCookie;

  const accept = (await headers()).get("accept-language") || "";
  const preferred = accept.split(",")[0]?.trim().slice(0, 2).toLowerCase();
  if (preferred && locales.includes(preferred)) return preferred;

  return defaultLocale;
}

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
      messages = en;
    }
  }

  const timeZone =
    process.env.NEXT_PUBLIC_DISPLAY_TIMEZONE ||
    Intl.DateTimeFormat().resolvedOptions().timeZone ||
    "UTC";

  return { locale, messages, timeZone, getMessageFallback, onError };
});
