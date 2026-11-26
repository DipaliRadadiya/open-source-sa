"use server";

import { cookies } from "next/headers";
import { locales } from "@/i18n/routing";

export async function setLocale(locale) {
  if (!locales.includes(locale)) return;
  (await cookies()).set("NEXT_LOCALE", locale, {
    path: "/",
    maxAge: 60 * 60 * 24 * 365,
    sameSite: "lax",
  });
}
