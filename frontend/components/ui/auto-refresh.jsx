"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";

export function AutoRefresh({ intervalMs = 10000, stopAfterMs = null, quiet = false }) {
  const router = useRouter();
  const t = useTranslations("common.autoRefresh");
  const [round, setRound] = useState(0);
  const [stopped, setStopped] = useState(false);

  useEffect(() => {
    // No setState in the effect body.
    const tick = () => {
      if (!document.hidden) router.refresh();
    };

    const id = setInterval(tick, intervalMs);
    document.addEventListener("visibilitychange", tick);

    const stop = stopAfterMs
      ? setTimeout(() => {
          clearInterval(id);
          setStopped(true);
        }, stopAfterMs)
      : null;

    return () => {
      clearInterval(id);
      if (stop) clearTimeout(stop);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [router, intervalMs, stopAfterMs, round]);

  const again = useCallback(() => {
    router.refresh();
    setStopped(false);
    setRound((n) => n + 1);
  }, [router]);

  if (!stopped || quiet) return null;

  return (
    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
      {t("stopped")}
      <Button variant="link" size="sm" className="h-auto p-0 text-xs" onClick={again}>
        <RefreshCw className="size-3" />
        {t("checkAgain")}
      </Button>
    </p>
  );
}
