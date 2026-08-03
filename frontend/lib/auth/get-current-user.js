import { cache } from "react";
import { cookies } from "next/headers";
import { serverLocale } from "@/lib/i18n/server-locale";
import { fetchWithRetry } from "@/lib/api/retry";

// Single cached `/auth/me` fetch per request. Returns the full payload:
// `{ user, impersonatedBy }`. `getCurrentUser` / `getImpersonator` derive from
// it so they share one request (deduped via React `cache`).
export const getMe = cache(async () => {
  // Read cookies OUTSIDE any try/catch: cookies() throws Next's internal
  // DynamicServerError to opt the route into dynamic rendering, and that
  // signal must be allowed to propagate — swallowing it makes Next
  // statically prerender the page as logged-out.
  const cookieStore = await cookies();
  const locale = await serverLocale();

  // Deliberately NOT wrapped in try/catch. "Signed out" and "we couldn't ask"
  // are different answers: only 401/419 mean the session is gone. Any other
  // failure must reach the error boundary, because swallowing it logs the user
  // out on a transient API hiccup.
  // Retried once on a 5xx: this runs on every page, so a single backend hiccup
  // would otherwise replace the whole app with an error card.
  const res = await fetchWithRetry(() =>
    fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/auth/me`, {
      headers: {
        Accept: "application/json",
        "Accept-Language": locale,
        cookie: cookieStore.toString(),
        // Sanctum only applies session (cookie) auth when the request looks
        // like it came from a trusted frontend domain, so forward our origin.
        Referer: process.env.NEXT_PUBLIC_APP_URL,
        Origin: process.env.NEXT_PUBLIC_APP_URL,
      },
      cache: "no-store",
    }),
  );

  // 401 unauthenticated, 419 expired session/CSRF — genuinely signed out.
  if (res.status === 401 || res.status === 419) {
    return { user: null, impersonatedBy: null };
  }

  if (!res.ok) {
    throw new Error(`auth/me responded ${res.status}`);
  }

  const data = await res.json();
  return {
    user: data?.user ?? null,
    impersonatedBy: data?.impersonated_by ?? null,
  };
});

export const getCurrentUser = cache(async () => (await getMe()).user);

// The admin who started an impersonated session (`{id, username}`), or null on
// a normal session. Drives the impersonation banner.
export const getImpersonator = cache(async () => (await getMe()).impersonatedBy);
