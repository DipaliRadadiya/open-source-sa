"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import {
  setGenericErrorMessage,
  setNoAnswerMessage,
  setRateLimitedMessage,
  setServerRestartingMessage,
} from "@/lib/api/generic-error";

export function ErrorCopy() {
  const t = useTranslations("errors");

  useEffect(() => {
    setGenericErrorMessage(t("title"));
    setRateLimitedMessage(t("rateLimited.body"));
    setNoAnswerMessage(t("noAnswer"));
    setServerRestartingMessage(t("serverRestarting"));
  }, [t]);

  return null;
}
