"use client";

import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Eye, Loader2 } from "lucide-react";
import { stopImpersonating } from "@/lib/auth/auth-actions";
import { Button } from "@/components/ui/button";

// Persistent banner shown while an admin is impersonating a user. `username` is
// the account being viewed as. "Stop" reverts to the admin and hard-navigates
// back to the admin Users list so SSR re-reads the restored session.
export function ImpersonationBanner({ username, admin }) {
  const t = useTranslations("impersonation.banner");
  const [stopping, setStopping] = useState(false);

  async function onStop() {
    setStopping(true);
    try {
      await stopImpersonating();
      window.location.href = "/admin/users";
    } catch (error) {
      toast.error(error.response?.data?.message || t("failed"));
      setStopping(false);
    }
  }

  return (
    <div className="relative flex flex-wrap items-center justify-center gap-x-3 gap-y-1.5 border-b border-warning/30 bg-background px-4 py-2 text-sm text-foreground before:pointer-events-none before:absolute before:inset-0 before:bg-warning/15 before:content-['']">
      <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 font-medium">
        <Eye className="size-4 shrink-0 text-warning" />
        {t.rich("viewingAs", {
          name: username,
          strong: (chunks) => <strong className="font-semibold">{chunks}</strong>,
        })}
        {admin ? (
          <span className="font-normal text-muted-foreground">
            {t("returnHint", { admin })}
          </span>
        ) : null}
      </span>
      <Button
        variant="outline"
        size="sm"
        onClick={onStop}
        disabled={stopping}
        className="h-7 border-warning/40 bg-background/60 hover:bg-background"
      >
        {stopping ? <Loader2 className="size-3.5 animate-spin" /> : null}
        {stopping ? t("stopping") : t("stop")}
      </Button>
    </div>
  );
}
