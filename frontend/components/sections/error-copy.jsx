"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import { setGenericErrorMessage } from "@/lib/api/generic-error";

/**
 * Hands the translated last-resort error sentence to the plain module that
 * needs it (see `lib/api/generic-error.js`).
 *
 * Renders nothing. It lives in the shells rather than in one page because the
 * function that reads it is called from every form in the panel.
 */
export function ErrorCopy() {
  const t = useTranslations("errors");

  useEffect(() => {
    setGenericErrorMessage(t("title"));
  }, [t]);

  return null;
}
