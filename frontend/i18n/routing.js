// ACTIVE locales — only those with a real `messages/<locale>.json`. These are
// the only ones offered in the switcher and accepted as a valid locale. To add
// a language: create its message file, then add its code here (+ a name below).
// Locale is resolved from the `NEXT_LOCALE` cookie server-side (i18n/request.js)
// — no URL prefix, no middleware. English is the source; partial locales fall
// back to English per-key.
export const locales = ["en", "es", "hi"];
export const defaultLocale = "en";

// Human-readable names for the switcher. Extra entries (for not-yet-active
// locales) are harmless — only codes present in `locales` are shown/used.
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
