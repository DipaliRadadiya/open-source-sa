import { cache } from "react";
import { cookies } from "next/headers";

// Single cached `/auth/me` fetch per request. Returns the full payload:
// `{ user, impersonatedBy }`. `getCurrentUser` / `getImpersonator` derive from
// it so they share one request (deduped via React `cache`).
export const getMe = cache(async () => {
  // Read cookies OUTSIDE any try/catch: cookies() throws Next's internal
  // DynamicServerError to opt the route into dynamic rendering, and that
  // signal must be allowed to propagate — swallowing it makes Next
  // statically prerender the page as logged-out.
  const cookieStore = await cookies();

  try {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/auth/me`, {
      headers: {
        Accept: "application/json",
        cookie: cookieStore.toString(),
        // Sanctum only applies session (cookie) auth when the request looks
        // like it came from a trusted frontend domain, so forward our origin.
        Referer: process.env.NEXT_PUBLIC_APP_URL,
        Origin: process.env.NEXT_PUBLIC_APP_URL,
      },
      cache: "no-store",
    });

    if (!res.ok) return { user: null, impersonatedBy: null };

    const data = await res.json();
    return {
      user: data?.user ?? null,
      impersonatedBy: data?.impersonated_by ?? null,
    };
  } catch {
    return { user: null, impersonatedBy: null };
  }
});

export const getCurrentUser = cache(async () => (await getMe()).user);

// The admin who started an impersonated session (`{id, username}`), or null on
// a normal session. Drives the impersonation banner.
export const getImpersonator = cache(async () => (await getMe()).impersonatedBy);
