"use client";

import { useCallback, useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2, Loader2, TriangleAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { startDriveConnect } from "@/lib/api/storage";
import { apiMessage } from "@/lib/api/error-message";

/**
 * The approval step for a user-owned Google Drive.
 *
 * One button. It asks the panel for a consent URL and sends the browser there;
 * Google sends it back to the callback page, which finishes the job. Nothing is
 * polled and nothing is typed — the previous device flow made the operator read
 * a code off one screen and type it into another, which existed only to avoid a
 * redirect URI.
 *
 * The redirect URI is *not* shown here — see {@link GoogleDriveRedirectUri},
 * which sits above the credential fields. It is needed when the Google OAuth
 * client is created, which is before there is a client ID to type into this
 * form and before this component is reachable at all.
 */
export function GoogleDriveConnect({ destination }) {
  const t = useTranslations("storage.oauth");
  const [state, setState] = useState("idle");
  const [error, setError] = useState(null);

  const connected = destination?.config?.connected;
  const account = destination?.config?.account_email;

  const start = useCallback(async () => {
    setError(null);
    setState("starting");

    try {
      const { data } = await startDriveConnect(destination.id);

      // A full navigation, not a popup: a popup here is blocked often enough
      // that the button would appear to do nothing, and Google's consent screen
      // is not something to render in 400 pixels.
      //
      // Nothing is rendered between here and leaving the page. An earlier
      // version set the redirect URI into state on this line to display it —
      // dead code, because the browser navigates away before React commits.
      // The URI belongs above the form anyway, where it is needed *before* the
      // OAuth client exists; see `GoogleDriveRedirectUri`.
      window.location.assign(data.oauth.authorize_url);
    } catch (e) {
      setError(apiMessage(e, t("start_failed")));
      setState("idle");
    }
  }, [destination, t]);

  if (connected) {
    return (
      <div className="rounded-lg border border-success/30 bg-success/5 p-3">
        <p className="flex items-center gap-2 text-sm font-medium">
          <CheckCircle2 className="size-4 shrink-0 text-success" />
          {account ? t("connectedAs", { account }) : t("connected")}
        </p>
        <p className="mt-1 text-xs leading-5 text-muted-foreground">{t("scopeNote")}</p>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="mt-3"
          onClick={start}
          disabled={state === "starting"}
        >
          {state === "starting" ? <Loader2 className="size-4 animate-spin" /> : null}
          {t("reconnect")}
        </Button>
      </div>
    );
  }

  return (
    <div className="rounded-lg border p-3">
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
