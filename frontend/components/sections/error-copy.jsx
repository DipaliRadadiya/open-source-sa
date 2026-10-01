"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import {
  setGenericErrorMessage,
  setNoAnswerMessage,
  setRateLimitedMessage,
} from "@/lib/api/generic-error";

/**
 * Hands the translated last-resort and rate-limit sentences to the plain module
 * that needs them (see `lib/api/generic-error.js`).
 * Renders nothing. Lives in the shells because every form in the panel calls the
 * reading function.
 */
export function ErrorCopy() {
  const t = useTranslations("errors");

  useEffect(() => {
    setGenericErrorMessage(t("title"));
    setRateLimitedMessage(t("rateLimited.body"));
    setNoAnswerMessage(t("noAnswer"));
  }, [t]);

  return null;
}
