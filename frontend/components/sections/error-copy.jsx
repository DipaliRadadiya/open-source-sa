"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import { setGenericErrorMessage, setRateLimitedMessage } from "@/lib/api/generic-error";

/**
 * Hands the translated last-resort and rate-limit sentences to the plain
 * module that needs them (see `lib/api/generic-error.js`).
 *
 * Renders nothing. It lives in the shells rather than in one page because the
 * function that reads it is called from every form in the panel.
 */
export function ErrorCopy() {
  const t = useTranslations("errors");

  useEffect(() => {
    setGenericErrorMessage(t("title"));
    setRateLimitedMessage(t("rateLimited.body"));
  }, [t]);

  return null;
}
