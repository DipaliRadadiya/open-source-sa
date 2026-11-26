"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { ShieldPlus, Loader2, TriangleAlert } from "lucide-react";
import { installFail2ban } from "@/lib/api/fail2ban";
import { parseApiDate } from "@/lib/format/api-date";
import { Button } from "@/components/ui/button";
import { apiMessage } from "@/lib/api/error-message";

const SLOW_AFTER_MS = 10 * 60 * 1000;

export function InstallPrompt({ canManage, install = null }) {
  const t = useTranslations("fail2ban");
  const router = useRouter();
  const [starting, setStarting] = useState(false);

  const installing = install?.status === "installing" || starting;
  const failed = install?.status === "failed" && !starting;

  const [slow, setSlow] = useState(false);
  const startedAtRaw = install?.started_at;

  useEffect(() => {
    const startedAt = parseApiDate(startedAtRaw);
    // One callback: a bare setState in the effect cascades.
    const tick = () =>
      setSlow(
        Boolean(installing && startedAt && Date.now() - startedAt.getTime() > SLOW_AFTER_MS),
      );
    const frame = requestAnimationFrame(tick);
    const id = installing && startedAt ? setInterval(tick, 30000) : null;
    return () => {
      cancelAnimationFrame(frame);
      if (id) clearInterval(id);
    };
  }, [installing, startedAtRaw]);

  async function start() {
    setStarting(true);
    try {
      await installFail2ban();
      toast.info(t("install.started"));
      router.refresh();
    } catch (error) {
      setStarting(false);
      toast.error(
        apiMessage(error, t("install.failed")),
      );
    }
  }

  const title = installing
    ? t("install.installingTitle")
    : failed
      ? t("install.failedTitle")
      : t("install.title");

  const body = installing
    ? t("install.installing")
    : failed
      ? (install?.reason_title ?? t("install.failedUnknown"))
      : t("install.body");

  return (
    <DisabledReasonProvider denied={!canManage}>
      <div className="flex flex-col items-center gap-4 rounded-xl border border-dashed py-16 text-center">
        <span
          className={
            failed
              ? "flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive"
              : "flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary"
          }
        >
          {installing ? (
            <Loader2 className="size-5 animate-spin" />
          ) : failed ? (
            <TriangleAlert className="size-5" />
          ) : (
            <ShieldPlus className="size-5" />
          )}
        </span>
  
        <div className="space-y-1">
          <p className="font-medium">{title}</p>
          <p className="mx-auto max-w-md text-sm leading-relaxed text-muted-foreground">{body}</p>
          {slow ? (
            <p role="status" className="mx-auto max-w-md pt-1 text-sm text-warning">
              {t("install.stalled")}
            </p>
          ) : null}
          {failed && install?.reference ? (
            <p className="pt-1 font-mono text-xs text-muted-foreground">{install.reference}</p>
          ) : null}
        </div>
  
        {installing || !canManage ? null : (
          <Button onClick={start}>
            {failed ? t("install.retry") : t("install.action")}
          </Button>
        )}
      </div>
    </DisabledReasonProvider>
  );
}
