import { cache } from "react";
import { cookies } from "next/headers";
import { serverLocale } from "@/lib/i18n/server-locale";
import { fetchWithRetry } from "@/lib/api/retry";
import { RateLimitedError } from "@/lib/api/rate-limited";
import { PanelUnavailableError } from "@/lib/api/unavailable";
import { RequestFailedError } from "@/lib/api/request-failed";
import { readErrorBody } from "@/lib/api/error-body";

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
  const url = `${process.env.NEXT_PUBLIC_API_URL}/api/auth/me`;

  // The transport error is caught HERE rather than left to the boundary: a
  // refused connection or a dead DNS name produces no response at all, so
  // without this the reader gets a digest for the one failure they could most
  // easily have fixed.
  let res;
  try {
    res = await fetchWithRetry(() =>
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
      }),
    );
  } catch (cause) {
    throw new RequestFailedError({ url, status: null, cause });
  }

  // 401 unauthenticated, 419 expired session/CSRF — genuinely signed out.
  if (res.status === 401 || res.status === 419) {
    return { user: null, impersonatedBy: null };
  }

  // Still rate-limited after the backoffs in fetchWithRetry. Thrown as its own
  // type so the layout can say "too many requests" instead of "went wrong".
  if (res.status === 429) throw new RateLimitedError("auth/me");

  // Maintenance mode — the panel is mid-update, or an update stopped after
  // `artisan down`. Its own type so the login page can say which, instead of
  // showing a digest for a server that is working exactly as instructed.
  if (res.status === 503) throw new PanelUnavailableError("auth/me");

  // Everything else carries the status and the URL, because SSR means there is
  // no Network tab row for the reader to open.
  if (!res.ok) {
    // The API's own explanation, when it gave one. It is the reason; ours is
    // only the category.
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
