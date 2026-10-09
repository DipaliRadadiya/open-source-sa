"use client";

import { useTranslations } from "next-intl";
import { KeyRound, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { MagicLoginDialog } from "@/components/applications/magic-login-dialog";
import { useMagicLogin } from "@/components/applications/use-magic-login";

// Button and dialog together so the Dashboard can stay a Server Component.
// `variant`/`size`: the application header makes it the page's main button.
export function MagicLoginLauncher({ appId, variant = "outline", size = "sm" }) {
  const t = useTranslations("applications.magicLogin");
  const { start, pending, phase, choice, closeChoice } = useMagicLogin(appId);

  return (
    <>
      <Button type="button" variant={variant} size={size} onClick={start} disabled={pending}>
        {pending ? <Loader2 className="size-4 animate-spin" /> : <KeyRound className="size-4" />}
        {phase === "fetching" ? t("fetchingUsers") : phase === "signing" ? t("redirecting") : t("action")}
      </Button>
      {/* Only while there is a choice, so each open remounts with the fresh administrator list. */}
      {choice ? (
        <MagicLoginDialog
          appId={appId}
          admins={choice.admins}
          open
          onOpenChange={(next) => !next && closeChoice()}
        />
      ) : null}
    </>
  );
}
