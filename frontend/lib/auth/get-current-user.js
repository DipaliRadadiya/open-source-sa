import { cache } from "react";
import { cookies } from "next/headers";
import { serverLocale } from "@/lib/i18n/server-locale";
import { fetchWithRetry } from "@/lib/api/retry";
import { RateLimitedError } from "@/lib/api/rate-limited";
import { PanelUnavailableError } from "@/lib/api/unavailable";
import { RequestFailedError } from "@/lib/api/request-failed";
import { readErrorBody } from "@/lib/api/error-body";

// One cached `/auth/me` per request; `getCurrentUser`/`getImpersonator` derive from it.
export const getMe = cache(async () => {
  // Read cookies OUTSIDE any try/catch: cookies() throws Next's internal
  // DynamicServerError, and swallowing it prerenders the page as logged-out.
  const cookieStore = await cookies();
  const locale = await serverLocale();

  // Only 401/419 mean signed out; other failures reach the error boundary rather
  // than log the user out. Retried once on a 5xx.
  const url = `${process.env.NEXT_PUBLIC_API_URL}/api/auth/me`;

  // Transport errors (refused connection, DNS) are wrapped so the boundary can
  // explain them rather than show only a digest.
  let res;
  try {
    res = await fetchWithRetry((signal) =>
      fetch(url, {
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
        signal,
      }),
    );
  } catch (cause) {
    throw new RequestFailedError({ url, status: null, cause });
  }

  // 401 unauthenticated, 419 expired session/CSRF — genuinely signed out.
  if (res.status === 401 || res.status === 419) {
    return { user: null, impersonatedBy: null };
  }

  // Still rate-limited after fetchWithRetry's backoffs; own type for its own message.
  if (res.status === 429) throw new RateLimitedError("auth/me");

  // Maintenance mode (mid-update, or an update stopped after `artisan down`).
  if (res.status === 503) throw new PanelUnavailableError("auth/me");

  // Carries status and URL: SSR failures have no Network tab entry.
  if (!res.ok) {
    const { message, debug } = await readErrorBody(res);
    throw new RequestFailedError({ url, status: res.status, serverMessage: message, debug });
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
export const getImpersonator = cache(
  async () => (await getMe()).impersonatedBy,
);
