// Only locales with a `messages/<locale>.json`. Partial locales fall back to English per key.
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
