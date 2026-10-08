import { cache } from "react";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { serverLocale } from "@/lib/i18n/server-locale";
import { fetchWithRetry } from "@/lib/api/retry";
import { RateLimitedError } from "@/lib/api/rate-limited";
import { PanelUnavailableError } from "@/lib/api/unavailable";
import { RequestFailedError } from "@/lib/api/request-failed";
import { readErrorBody } from "@/lib/api/error-body";
import { signedOutPath } from "@/lib/auth/signed-out-path";

// With an application id the API also filters by what the site type supports.
export const getPermissions = cache(async (level, applicationId) => {
  const cookieStore = await cookies();
  const locale = await serverLocale();

  const query = new URLSearchParams();
  if (level) query.set("level", level);
  if (applicationId) query.set("application_id", String(applicationId));
  const suffix = query.size ? `?${query}` : "";

  // An empty catalog means "may do nothing", so a failed request must NEVER degrade to [].
  // 401/419 redirects here because layouts do not re-run on client navigation.
  const url = `${process.env.NEXT_PUBLIC_API_URL}/api/permissions${suffix}`;

  let res;
  try {
    res = await fetchWithRetry((signal) =>
      fetch(url, {
        headers: {
          Accept: "application/json",
          "Accept-Language": locale,
          cookie: cookieStore.toString(),
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

  if (res.status === 401 || res.status === 419) redirect(await signedOutPath());

  // Rate-limited and mid-update are not "may do nothing"; throw instead of [].
  if (res.status === 429) throw new RateLimitedError("permissions");

  if (res.status === 503) throw new PanelUnavailableError("permissions");

  if (!res.ok) {
    // Carry the API's own explanation when it gave one.
    const { message, debug } = await readErrorBody(res);
    throw new RequestFailedError({ url, status: res.status, serverMessage: message, debug });
  }

  const data = await res.json();
  return Array.isArray(data?.permissions) ? data.permissions : [];
});
