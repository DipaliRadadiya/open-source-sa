import { cookies } from "next/headers";
import { serverLocale } from "@/lib/i18n/server-locale";

/**
 * Server-side GET against the Laravel API, forwarding the Sanctum session
 * cookie plus Referer/Origin (required for stateful-domain auth). Mirrors the
 * pattern used by get-current-user / get-permissions.
 *
 * IMPORTANT: cookies() is awaited by the CALLER-facing path here (top of the
 * function, outside any try/catch) so Next's DynamicServerError propagates and
 * the route is correctly treated as dynamic — swallowing it caused the earlier
 * auth redirect loop.
 *
 * @param {string} path  API path beginning with "/" (relative to `${API}/api`)
 * @param {{ searchParams?: Record<string, string | number | undefined> }} [opts]
 * @returns {Promise<Response>} the raw fetch Response (caller inspects .ok/.status)
 */
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

  return fetch(`${process.env.NEXT_PUBLIC_API_URL}/api${path}${qs}`, {
    headers: {
      Accept: "application/json",
      "Accept-Language": locale,
      cookie: cookieStore.toString(),
      Referer: process.env.NEXT_PUBLIC_APP_URL,
      Origin: process.env.NEXT_PUBLIC_APP_URL,
    },
    cache: "no-store",
  });
}
