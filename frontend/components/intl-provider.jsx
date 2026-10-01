"use client";

import { NextIntlClientProvider } from "next-intl";
import { getMessageFallback, onError } from "@/i18n/message-fallback";

// Functions (`getMessageFallback`, `onError`) cannot cross from a Server Component;
// `i18n/request.js` only covers server rendering.
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
