import { getLocale } from "next-intl/server";

// Resolved UI locale for the current request (cookie → Accept-Language → en,
// per i18n/request.js). Used to stamp `Accept-Language` on server-side API
// calls so the Laravel backend localizes responses to the same language the
// UI is rendering in.
export async function serverLocale() {
  try {
    return await getLocale();
  } catch {
    return "en";
  }
}
