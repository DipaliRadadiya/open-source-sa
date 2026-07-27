import { defineRouting } from "next-intl/routing";

export const routing = defineRouting({
  locales: ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"],
  defaultLocale: "en",
  localePrefix: "never",
});
