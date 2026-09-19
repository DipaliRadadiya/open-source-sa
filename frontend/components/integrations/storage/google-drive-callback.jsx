"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { CheckCircle2, Loader2, TriangleAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { completeDriveConnect } from "@/lib/api/storage";
import { apiMessage } from "@/lib/api/error-message";

/*
 * A real link, not `router.push` in an onClick.
 *
 * Reported as "Back to Storage destinations button not working". This is the
 * only navigation button in the panel that was written as a click handler —
 * every other one is `<Button asChild><Link>` — and it sits on a route that
 * has just called `router.refresh()` on a force-dynamic page, so a push queued
 * behind that transition does nothing visible.
 *
 * An anchor does not depend on the router being idle. It also restores
 * middle-click and open-in-new-tab, which an onClick silently swallows.
 */
const STORAGE_PAGE = "/integrations/storage";

/**
 * Turns Google's redirect into an authenticated request, once.
 *
 * **Once is the whole difficulty.** The authorization code is single-use and
 * the sealed `state` behind it is burned on first use, so a second exchange
 * fails by design — and React runs effects twice in development's strict mode.
 * A naive effect would therefore succeed, immediately re-fire, and paint a
 * failure over a connection that actually worked. The ref guards that, and it
 * is set before the await rather than after, because two effect runs in the
 * same tick would both pass a check that only flips on completion.
 */
export function GoogleDriveCallback({ code, state, deniedError }) {
  const t = useTranslations("storage.oauth");
  const router = useRouter();
  const [result, setResult] = useState(null);
  const started = useRef(false);

  /*
   * Derived during render, not set from the effect. Both of these are pure
   * functions of the props — there is nothing to synchronise with, and writing
   * them through setState would be a cascading render that React Compiler's
   * lint rule rejects outright. Only the exchange below is genuinely an effect,
   * because only it talks to something outside React.
   *
   * Google says no by sending `error`, not by withholding `code`. Naming that
   * case apart is the difference between "you cancelled" and "we broke".
   */
  const blocked = deniedError
    ? deniedError === "access_denied"
      ? t("denied")
      : t("token_failed")
    : !code || !state
      ? t("state_invalid")
      : null;

  useEffect(() => {
    if (blocked || started.current) return;
    started.current = true;

    completeDriveConnect({ code, state })
      .then(({ data }) => {
        if (data.oauth.status === "connected") {
          // The destinations list is server-rendered, so without this the row
          // behind this page keeps showing "not connected" until a hard reload.
          router.refresh();
          setResult({ status: "connected", message: null });
          return;
        }

        setResult({ status: "failed", message: data.oauth.message ?? t("token_failed") });
      })
      .catch((e) => {
        setResult({ status: "failed", message: apiMessage(e, t("token_failed")) });
      });
  }, [blocked, code, state, router, t]);

  const status = blocked ? "failed" : (result?.status ?? "working");
  const message = blocked ?? result?.message ?? null;

  if (status === "working") {
    return (
      <div className="flex items-center gap-2 rounded-lg border p-4 text-sm">
        <Loader2 className="size-4 animate-spin" />
        {t("finishing")}
      </div>
    );
  }

  if (status === "connected") {
    return (
      <div className="rounded-lg border border-success/30 bg-success/5 p-4">
        <p className="flex items-center gap-2 text-sm font-medium">
          <CheckCircle2 className="size-4 shrink-0 text-success" />
          {t("connected")}
        </p>
        <p className="mt-1 text-xs leading-5 text-muted-foreground">{t("scopeNote")}</p>
        <Button asChild size="sm" className="mt-3">
          <Link href={STORAGE_PAGE}>{t("backToStorage")}</Link>
        </Button>
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-destructive/40 bg-destructive/10 p-4">
      <p className="flex items-center gap-2 text-sm font-medium">
        <TriangleAlert className="size-4 shrink-0 text-destructive" />
        {t("callbackFailed")}
      </p>
      {message ? <p className="mt-1 text-xs leading-relaxed">{message}</p> : null}
      {/* Back to the destination, not a retry button here: starting again needs
          a fresh `state`, which only the Connect button on the row can issue. */}
      <Button asChild variant="outline" size="sm" className="mt-3">
        <Link href={STORAGE_PAGE}>{t("backToStorage")}</Link>
      </Button>
    </div>
  );
}
