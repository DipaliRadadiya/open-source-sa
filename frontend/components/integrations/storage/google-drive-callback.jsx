"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { CheckCircle2, Loader2, TriangleAlert } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { completeDriveConnect } from "@/lib/api/storage";
import { apiMessage } from "@/lib/api/error-message";

const STORAGE_PAGE = "/integrations/storage";

/**
 * The card this page is, in each of its three states.
 *
 * Presentational and exported so all three can be rendered — the connected
 * state is otherwise unreachable without a live single-use Google code, which
 * is exactly the state worth looking at.
 *
 * A centred card rather than the panel's page shell, like the 404: this screen
 * has one job, one outcome and one way out. As a normal page it carried a
 * `PageHeader` reading "Connecting Google Drive" above a box announcing
 * "Connected to Google Drive" — the heading was written for the working state
 * and never changed, so the page said two different things at once, and on a
 * failure "Finishing the approval you just gave Google" was simply untrue. The
 * heading belongs to the state, so the state owns it.
 */
export function CallbackCard({ status, message }) {
  const t = useTranslations("storage.oauth");

  const view = {
    working: {
      icon: Loader2,
      spin: true,
      tone: "bg-muted text-muted-foreground",
      title: t("callbackTitle"),
      body: t("finishing"),
    },
    connected: {
      icon: CheckCircle2,
      tone: "bg-success/10 text-success",
      title: t("connected"),
      // The reassurance is the body here rather than fine print: this is the
      // end of a five-step setup, and "what can it actually see" is the
      // question somebody finishes that setup holding.
      body: t("scopeNote"),
    },
    failed: {
      icon: TriangleAlert,
      tone: "bg-destructive/10 text-destructive",
      title: t("callbackFailed"),
      body: message,
    },
  }[status];

  const Icon = view.icon;

  return (
    <div className="w-full max-w-md rounded-2xl border bg-card p-6 text-center shadow-e1 ring-1 ring-foreground/[0.07]">
      <span
        className={cn(
          "mx-auto flex size-12 items-center justify-center rounded-full",
          view.tone,
        )}
      >
        <Icon className={cn("size-6", view.spin && "animate-spin")} aria-hidden />
      </span>

      <h1 className="mt-4 text-lg font-semibold tracking-tight">{view.title}</h1>
      {view.body ? (
        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{view.body}</p>
      ) : null}

      {/* No way out while the exchange is in flight — there is nothing to
          decide yet, and a button here would invite leaving mid-request. */}
      {status !== "working" ? (
        <Button
          asChild
          variant={status === "connected" ? "default" : "outline"}
          className="mt-6 w-full"
        >
          {/* A link, not a click handler: middle-click and "open in new tab"
              both work, and it is the browser's own navigation. */}
          <a href={STORAGE_PAGE}>{t("backToStorage")}</a>
        </Button>
      ) : null}
    </div>
  );
}

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

  return <CallbackCard status={status} message={message} />;
}
