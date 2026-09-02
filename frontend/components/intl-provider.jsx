"use client";

import { NextIntlClientProvider } from "next-intl";
import { getMessageFallback, onError } from "@/i18n/message-fallback";

/**
 * `NextIntlClientProvider` with the panel's own missing-key handling attached.
 *
 * This wrapper exists for one reason: `getMessageFallback` and `onError` are
 * functions, and functions do not survive the crossing from a Server Component
 * to a client one. Setting them in `i18n/request.js` covers server rendering
 * only — and almost every screen in this panel is a client component, so
 * without this the fallback would be absent exactly where it is needed.
 *
 * See i18n/message-fallback.js for what the fallback does and why.
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
