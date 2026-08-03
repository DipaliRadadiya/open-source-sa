import { cache } from "react";
import { cookies } from "next/headers";
import { serverLocale } from "@/lib/i18n/server-locale";
import { fetchWithRetry } from "@/lib/api/retry";

export const getPermissions = cache(async () => {
  const cookieStore = await cookies();
  const locale = await serverLocale();

  // An empty catalog means "this user may do nothing", which is what every
  // page gate reads. A failed request must therefore NOT degrade to [] — that
  // turns an API hiccup into a silent "you don't have permission" redirect.
  // Only 401/419 (signed out) return empty; the layout redirects to /login.
  // Retried once on a 5xx — like the session, this gates every page, so one
  // backend hiccup must not blank the app.
  const res = await fetchWithRetry(() =>
    fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/permissions`, {
      headers: {
        Accept: "application/json",
        "Accept-Language": locale,
        cookie: cookieStore.toString(),
        Referer: process.env.NEXT_PUBLIC_APP_URL,
        Origin: process.env.NEXT_PUBLIC_APP_URL,
      },
      cache: "no-store",
    }),
  );

  if (res.status === 401 || res.status === 419) return [];

  if (!res.ok) {
    throw new Error(`permissions responded ${res.status}`);
  }

  const data = await res.json();
  return Array.isArray(data?.permissions) ? data.permissions : [];
});
