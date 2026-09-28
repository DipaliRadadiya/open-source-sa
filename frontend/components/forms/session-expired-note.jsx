"use client";

import { useSyncExternalStore } from "react";
import { useTranslations } from "next-intl";
import { Clock } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { peekRememberedPath } from "@/lib/auth/last-path";

/**
 * Says why the reader is looking at a sign-in form they did not ask for.
 *
 * A session that runs out mid-task used to drop them here with no word about
 * it — reading as a crash or a logout they did not make. The tab still holds
 * the screen they were on (see RememberPath), and a deliberate sign-out clears
 * it, so its presence is exactly "your session ended by itself". The server
 * has no sessionStorage, so it renders nothing and the browser fills it in.
 */
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
