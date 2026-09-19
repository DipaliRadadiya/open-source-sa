"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2, ExternalLink, Loader2, TriangleAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { startDriveConnect, pollDriveConnect } from "@/lib/api/storage";
import { apiMessage } from "@/lib/api/error-message";

/**
 * The approval step for a user-owned Google Drive.
 *
 * Shows a short code and a link; the operator opens the link on whatever
 * device is to hand, types the code, approves. No redirect URL is involved
 * anywhere — which is the only reason this works on a default install, where
 * the panel lives at a nip.io hostname Google would refuse as a redirect
 * target, behind a self-signed certificate.
 *
 * Polling happens here rather than on the server: one request asks one
 * question, so no worker is held open for the half hour a human might take.
 */
export function GoogleDriveConnect({ destination, onConnected }) {
  const t = useTranslations("storage.oauth");
  const [state, setState] = useState("idle");
  const [code, setCode] = useState(null);
  const [error, setError] = useState(null);

  // Held in a ref rather than state: the polling loop reads them every tick,
  // and putting them in state would restart the interval on every answer.
  const timer = useRef(null);
  const deadline = useRef(0);

  const stop = useCallback(() => {
    if (timer.current) {
      clearInterval(timer.current);
      timer.current = null;
    }
  }, []);

  // Clearing on unmount matters more than usual here: the dialog is closable
  // mid-approval, and a survivor would keep polling a destination nobody is
  // looking at until the code expired half an hour later.
  useEffect(() => stop, [stop]);

  const connected = destination?.config?.connected;
  const account = destination?.config?.account_email;

  const poll = useCallback(async () => {
    // The code outlived its window while the tab sat open. Say so rather than
    // polling a code Google has already forgotten.
    if (Date.now() > deadline.current) {
      stop();
      setState("idle");
      setError(t("code_expired"));
      return;
    }

    try {
      const { data } = await pollDriveConnect(destination.id);

      if (data.oauth.status === "approved") {
        stop();
        setState("connected");
        onConnected?.(data.storage_destination);
        return;
      }

      // `pending` and `slow_down` both mean keep waiting. Everything else is
      // over, and carries a finished sentence from the API.
      if (!["pending", "slow_down"].includes(data.oauth.status)) {
        stop();
        setState("idle");
        setError(data.oauth.message ?? null);
      }
    } catch (e) {
      // A blinking network is not a refusal. The code is still valid and the
      // operator may be mid-approval, so the loop keeps going.
      setError(apiMessage(e, t("start_failed")));
    }
  }, [destination, onConnected, stop, t]);

  /*
   * Both handlers are memoized, not merely defined in the body. React Compiler
   * refuses `Date.now()` in an unmemoized function here — it cannot prove the
   * call does not happen during render, and an impure read during render is
   * how you get two components disagreeing about what time it is.
   */
  const start = useCallback(async () => {
    setError(null);
    setState("starting");

    try {
      const { data } = await startDriveConnect(destination.id);
      setCode(data.oauth);
      setState("waiting");
      deadline.current = Date.now() + data.oauth.expires_in * 1000;
      // Google's own interval, not one we invented. Polling faster than it
      // earns `slow_down` and slows the whole thing down.
      timer.current = setInterval(poll, Math.max(2, data.oauth.interval) * 1000);
    } catch (e) {
      setError(apiMessage(e, t("start_failed")));
      setState("idle");
    }
  }, [destination, poll, t]);

  if (connected && state !== "waiting") {
    return (
      <div className="rounded-lg border border-success/30 bg-success/5 p-3">
        <p className="flex items-center gap-2 text-sm font-medium">
          <CheckCircle2 className="size-4 shrink-0 text-success" />
          {account ? t("connectedAs", { account }) : t("connected")}
        </p>
        <p className="mt-1 text-xs leading-5 text-muted-foreground">{t("scopeNote")}</p>
        <Button type="button" variant="outline" size="sm" className="mt-3" onClick={start}>
          {t("reconnect")}
        </Button>
      </div>
    );
  }

  return (
    <div className="rounded-lg border p-3">
      {state === "waiting" && code ? (
        <div className="space-y-3">
          <p className="text-sm">{t("step1")}</p>
          <a
            href={code.verification_url}
            target="_blank"
            rel="noreferrer noopener"
            className="inline-flex items-center gap-1.5 text-sm font-medium underline underline-offset-4"
          >
            {code.verification_url}
            <ExternalLink className="size-3.5" />
          </a>
          <p className="text-sm">{t("step2")}</p>
          {/* Big and monospaced because it is read off one screen and typed
              into another, by hand, and an l/1 mix-up costs the whole attempt. */}
          <p className="select-all rounded-md border bg-muted/40 px-3 py-2 text-center font-mono text-xl tracking-[0.3em]">
            {code.user_code}
          </p>
          <p className="flex items-center gap-2 text-xs text-muted-foreground">
            <Loader2 className="size-3.5 animate-spin" />
            {t("waiting")}
          </p>
        </div>
      ) : (
        <>
          <p className="text-sm">{t("intro")}</p>
          <Button
            type="button"
            size="sm"
            className="mt-3"
            onClick={start}
            disabled={state === "starting"}
          >
            {state === "starting" ? <Loader2 className="size-4 animate-spin" /> : null}
            {t("connect")}
          </Button>
        </>
      )}

      {/* Same shape as the provider warning above it, so a failure here reads
          as part of this panel rather than as a new kind of thing. */}
      {error ? (
        <div className="mt-3 flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-xs leading-relaxed">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-destructive" />
          <p>{error}</p>
        </div>
      ) : null}
    </div>
  );
}
