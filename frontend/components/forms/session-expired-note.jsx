"use client";

import { useSyncExternalStore } from "react";
import { useTranslations } from "next-intl";
import { Clock } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { peekRememberedPath } from "@/lib/auth/last-path";

// A remembered path means expiry, since sign-out clears it. Client-only (sessionStorage).
const noSubscribe = () => () => {};
const readExpired = () => Boolean(peekRememberedPath());
const readOnServer = () => false;

export function SessionExpiredNote() {
  const t = useTranslations("auth");
  const expired = useSyncExternalStore(noSubscribe, readExpired, readOnServer);

  if (!expired) return null;

  return (
    <Alert>
      <Clock />
      <AlertDescription>{t("sessionExpired")}</AlertDescription>
    </Alert>
  );
}
