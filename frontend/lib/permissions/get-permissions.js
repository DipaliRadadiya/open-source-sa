import { cache } from "react";
import { cookies } from "next/headers";
import { serverLocale } from "@/lib/i18n/server-locale";
import { fetchWithRetry } from "@/lib/api/retry";
import { RateLimitedError } from "@/lib/api/rate-limited";
import { PanelUnavailableError } from "@/lib/api/unavailable";
import { RequestFailedError } from "@/lib/api/request-failed";
import { readErrorBody } from "@/lib/api/error-body";

/**
 * `level`/`applicationId` are forwarded verbatim: `?level=application&
 * application_id=7` returns THAT site's menu with both filters applied
 * server-side — the user's grants and what the site type can actually do. The
 * frontend must not re-derive the second one; it cannot.
 */
export const getPermissions = cache(async (level, applicationId) => {
  const cookieStore = await cookies();
  const locale = await serverLocale();

  const query = new URLSearchParams();
  if (level) query.set("level", level);
  if (applicationId) query.set("application_id", String(applicationId));
  const suffix = query.size ? `?${query}` : "";

  // An empty catalog means "this user may do nothing", which is what every
  // page gate reads. A failed request must therefore NOT degrade to [] — that
  // turns an API hiccup into a silent "you don't have permission" redirect.
  // Only 401/419 (signed out) return empty; the layout redirects to /login.
  // Retried once on a 5xx — like the session, this gates every page, so one
  // backend hiccup must not blank the app.
  const url = `${process.env.NEXT_PUBLIC_API_URL}/api/permissions${suffix}`;

  let res;
  try {
    res = await fetchWithRetry(() =>
      fetch(url, {
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
  } catch (cause) {
    throw new RequestFailedError({ url, status: null, cause });
  }

  if (res.status === 401 || res.status === 419) return [];

  // Rate-limited, not "may do nothing" — returning [] here would redirect the
  // user out of the page they asked for as though they lacked permission.
  if (res.status === 429) throw new RateLimitedError("permissions");

  // Same reasoning as the 429 above: mid-update is not "may do nothing".
  if (res.status === 503) throw new PanelUnavailableError("permissions");

  if (!res.ok) {
    // The API's own explanation, when it gave one. It is the reason; ours is
    // only the category.
    const { message, debug } = await readErrorBody(res);
    throw new RequestFailedError({ url, status: res.status, serverMessage: message, debug });
  }

  const data = await res.json();
  return Array.isArray(data?.permissions) ? data.permissions : [];
});
