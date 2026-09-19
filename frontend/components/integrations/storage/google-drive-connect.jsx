"use client";

import { useCallback, useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2, Loader2, TriangleAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
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
 * The redirect URI is shown here rather than described, because it is the one
 * string an operator has to paste into Google Cloud Console and Google compares
 * it byte for byte. A URI someone retyped with a trailing slash fails at the
 * very end of the flow, after consent, which is the worst possible place to
 * discover a typo.
 */
export function GoogleDriveConnect({ destination }) {
  const t = useTranslations("storage.oauth");
  const [state, setState] = useState("idle");
  const [error, setError] = useState(null);
  const [redirectUri, setRedirectUri] = useState(null);

  const connected = destination?.config?.connected;
  const account = destination?.config?.account_email;

  const start = useCallback(async () => {
    setError(null);
    setState("starting");

    try {
      const { data } = await startDriveConnect(destination.id);

      // Shown before navigating, so a `redirect_uri_mismatch` on the way back
      // lands on a page already displaying the value that had to match.
      setRedirectUri(data.oauth.redirect_uri);

      // A full navigation, not a popup: a popup here is blocked often enough
      // that the button would appear to do nothing, and Google's consent screen
      // is not something to render in 400 pixels.
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

      {redirectUri ? (
        <div className="mt-3 space-y-1.5">
          <p className="text-xs text-muted-foreground">{t("redirectUriLabel")}</p>
          <div className="flex items-center gap-2 rounded-md border bg-muted/40 px-2 py-1.5">
            {/* `break-all` rather than truncation: this is copied by hand as
                often as by button, and a URI with an ellipsis in the middle is
                worse than one that wraps. */}
            <code className="min-w-0 flex-1 break-all font-mono text-xs">{redirectUri}</code>
            <CopyButton value={redirectUri} label={t("copyRedirectUri")} />
          </div>
        </div>
      ) : null}

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
