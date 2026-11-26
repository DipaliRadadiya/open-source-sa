import { getLocale } from "next-intl/server";

export async function serverLocale() {
  try {
    return await getLocale();
  } catch {
    return "en";
  }
}
