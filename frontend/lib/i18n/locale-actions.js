"use server";

import { cookies } from "next/headers";
import { locales } from "@/i18n/routing";

// The caller refreshes the router so server components re-render in the new language.
export async function setLocale(locale) {
  if (!locales.includes(locale)) return;
  (await cookies()).set("NEXT_LOCALE", locale, {
    path: "/",
    maxAge: 60 * 60 * 24 * 365, // 1 year
    sameSite: "lax",
  });
}
