"use client";

import { NextIntlClientProvider } from "next-intl";
import { getMessageFallback, onError } from "@/i18n/message-fallback";

/**
 * `NextIntlClientProvider` with the panel's missing-key handling attached.
 * Needed because functions (`getMessageFallback`, `onError`) cannot cross from
 * a Server Component; `i18n/request.js` only covers server rendering.
 * See i18n/message-fallback.js.
 */
export function IntlProvider({ locale, messages, children }) {
  return (
    <NextIntlClientProvider
      locale={locale}
      messages={messages}
      getMessageFallback={getMessageFallback}
      onError={onError}
    >
      {children}
    </NextIntlClientProvider>
  );
}
