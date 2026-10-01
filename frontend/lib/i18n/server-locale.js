import { getLocale } from "next-intl/server";

// Stamped as `Accept-Language` on server-side API calls so the backend matches the UI language.
export async function serverLocale() {
  try {
    return await getLocale();
  } catch {
    return "en";
  }
}
