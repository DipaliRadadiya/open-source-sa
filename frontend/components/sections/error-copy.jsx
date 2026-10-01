"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import {
  setGenericErrorMessage,
  setNoAnswerMessage,
  setRateLimitedMessage,
} from "@/lib/api/generic-error";

// Hands translated fallback sentences to `lib/api/generic-error.js`. Lives in the shells since every form needs them.
export function ErrorCopy() {
  const t = useTranslations("errors");

  useEffect(() => {
    setGenericErrorMessage(t("title"));
    setRateLimitedMessage(t("rateLimited.body"));
    setNoAnswerMessage(t("noAnswer"));
  }, [t]);

  return null;
}
