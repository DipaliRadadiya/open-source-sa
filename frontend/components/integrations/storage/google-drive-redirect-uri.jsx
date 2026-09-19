"use client";

import { useTranslations } from "next-intl";

import { CopyButton } from "@/components/ui/copy-button";

/**
 * The callback URL an operator pastes into their Google OAuth client.
 *
 * **Shown before anything is connected, on purpose.** This string is needed at
 * the point the OAuth client is *created* in Google Cloud Console — which is
 * before there is a client ID to paste into the panel, and before a destination
 * exists at all. Revealing it only after pressing Connect would hand it over
 * one step after the step that needs it, and the failure for getting it wrong
 * (`redirect_uri_mismatch`) arrives right at the end of the flow, after consent
 * has already been given.
 *
 * Copyable rather than merely readable, because Google compares it byte for
 * byte: a retyped URL with a trailing slash, or `http` for `https`, fails in a
 * way that says nothing about which character is wrong.
 */
export function GoogleDriveRedirectUri({ uri }) {
  const t = useTranslations("storage.oauth");

  if (!uri) return null;

  return (
    <div className="space-y-1.5 rounded-lg border bg-muted/30 p-3">
      <p className="text-xs font-medium">{t("redirectUriLabel")}</p>
      <div className="flex items-center gap-2 rounded-md border bg-background px-2 py-1.5">
        {/* `break-all` rather than truncation: this is copied by hand as often
            as by button, and a URL with an ellipsis through the middle is worse
            than one that wraps. */}
        <code className="min-w-0 flex-1 break-all font-mono text-xs">{uri}</code>
        <CopyButton value={uri} label={t("copyRedirectUri")} />
      </div>
      <p className="text-xs leading-5 text-muted-foreground">{t("redirectUriHint")}</p>
    </div>
  );
}
