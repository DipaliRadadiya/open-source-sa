// Active locales: only those with a `messages/<locale>.json`. To add a language,
// create its message file, then add its code here and a name below.
// Resolved from the `NEXT_LOCALE` cookie server-side (i18n/request.js); partial
// locales fall back to English per-key.
export const locales = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
export const defaultLocale = "en";

// Names for the switcher; only codes present in `locales` are used.
export const localeNames = {
  en: "English",
  es: "Español",
  de: "Deutsch",
  fr: "Français",
  pt: "Português",
  ja: "日本語",
  ru: "Русский",
  hi: "हिन्दी",
};
