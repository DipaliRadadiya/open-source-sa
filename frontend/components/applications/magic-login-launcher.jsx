"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { KeyRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { MagicLoginDialog } from "@/components/applications/magic-login-dialog";

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
  const [open, setOpen] = useState(false);
  // Bumped on every open so the dialog remounts with empty state. The
  // administrator list must never be the one from last time: an account that
  // was an administrator then may not be one now, and a stale name offers a
  // refusal the user cannot explain.
  const [run, setRun] = useState(0);

  return (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => {
          setRun((n) => n + 1);
          setOpen(true);
        }}
      >
        <KeyRound className="size-4" />
        {t("action")}
      </Button>
      <MagicLoginDialog key={run} appId={appId} open={open} onOpenChange={setOpen} />
    </>
  );
}
