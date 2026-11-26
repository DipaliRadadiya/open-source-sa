import { cache } from "react";
import { cookies } from "next/headers";
import { serverLocale } from "@/lib/i18n/server-locale";
import { fetchWithRetry } from "@/lib/api/retry";
import { RateLimitedError } from "@/lib/api/rate-limited";
import { PanelUnavailableError } from "@/lib/api/unavailable";
import { RequestFailedError } from "@/lib/api/request-failed";
import { readErrorBody } from "@/lib/api/error-body";

export const getMe = cache(async () => {
  const cookieStore = await cookies();
  const locale = await serverLocale();

  // Only 401/419 mean signed out; anything else goes to the error boundary.
  const url = `${process.env.NEXT_PUBLIC_API_URL}/api/auth/me`;

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

  if (res.status === 401 || res.status === 419) {
    return { user: null, impersonatedBy: null };
  }

  if (res.status === 429) throw new RateLimitedError("auth/me");

  if (res.status === 503) throw new PanelUnavailableError("auth/me");

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

export const getImpersonator = cache(
  async () => (await getMe()).impersonatedBy,
);
