import { useTranslations } from "next-intl";

import { cn } from "@/lib/utils";
import { CopyButton } from "@/components/ui/copy-button";

/**
 * The callback URL to paste into the Google OAuth client.
 *
 * Shown before anything is connected: it is needed when the OAuth client is
 * created, and a mistake (`redirect_uri_mismatch`) only surfaces after consent.
 * Copyable because Google compares it byte for byte.
 */
export function GoogleDriveRedirectUri({ uri, className }) {
  const t = useTranslations("storage.oauth");

  if (!uri) return null;

  return (
    <div className={cn("space-y-1.5 rounded-lg border bg-muted/30 p-3", className)}>
      <p className="text-xs font-medium">{t("redirectUriLabel")}</p>
      <div className="flex items-center gap-2 rounded-md border bg-background px-2 py-1.5">
        {/* `break-all` rather than truncation: it may be copied by hand. */}
        <code className="min-w-0 flex-1 break-all font-mono text-xs">{uri}</code>
        <CopyButton value={uri} label={t("copyRedirectUri")} />
      </div>
      <p className="text-xs leading-5 text-muted-foreground">{t("redirectUriHint")}</p>
    </div>
  );
}
