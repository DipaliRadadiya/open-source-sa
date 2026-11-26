import { cookies } from "next/headers";
import { serverLocale } from "@/lib/i18n/server-locale";
import { retryOnTransportFailure } from "@/lib/api/retry";

export async function serverFetch(path, { searchParams } = {}) {
  const cookieStore = await cookies();
  const locale = await serverLocale();

  let qs = "";
  if (searchParams) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(searchParams)) {
      if (value !== undefined && value !== null && value !== "") {
        params.set(key, String(value));
      }
    }
    const str = params.toString();
    if (str) qs = `?${str}`;
  }

  // Retry once so an nginx reload mid-deploy doesn't fail the whole page.
  return retryOnTransportFailure((signal) =>
    fetch(`${process.env.NEXT_PUBLIC_API_URL}/api${path}${qs}`, {
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
}
