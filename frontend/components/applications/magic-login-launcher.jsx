"use client";

import { useTranslations } from "next-intl";
import { KeyRound, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { MagicLoginDialog } from "@/components/applications/magic-login-dialog";
import { useMagicLogin } from "@/components/applications/use-magic-login";

/**
 * The button and its dialog, together, so the Dashboard can stay a Server
 * Component. Only the open state lives on the client — pushing the boundary
 * down to the leaf rather than marking the whole page "use client".
 *
 * Whether this renders at all is decided on the server by the permission,
 * which is itself filtered by site type. Nothing here re-asks that question.
 */
export function MagicLoginLauncher({ appId }) {
  const t = useTranslations("applications.magicLogin");
  const { start, pending, choice, closeChoice } = useMagicLogin(appId);

  return (
    <>
      <Button type="button" variant="outline" size="sm" onClick={start} disabled={pending}>
        {pending ? <Loader2 className="size-4 animate-spin" /> : <KeyRound className="size-4" />}
        {t("action")}
      </Button>
      {/*
       * Rendered only while there is a choice, which also remounts it on every
       * open — so the administrator list is always the one just fetched rather
       * than the one from last time.
       */}
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
