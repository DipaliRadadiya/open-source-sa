"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ShieldPlus, Loader2, TriangleAlert } from "lucide-react";
import { installFail2ban } from "@/lib/api/fail2ban";
import { parseApiDate } from "@/lib/format/api-date";
import { Button } from "@/components/ui/button";
import { apiMessage } from "@/lib/api/error-message";

// apt is allowed ten minutes server-side. Past that we still do not claim it
// failed — only the server knows that — but we stop implying it is on track.
const SLOW_AFTER_MS = 10 * 60 * 1000;

/**
 * Not installed is a normal state on a fresh server, so the resting state is an
 * invitation rather than an error: what fail2ban does, and one button.
 *
 * The three states come from the API's `install` object, not from this
 * component's memory of having clicked. That is the whole point: `installed` is
 * a boolean derived from the package being on disk, so a screen built on it
 * alone shows nothing for the ten minutes apt is allowed and nothing at all
 * when the install fails. Reading the server also means a reload keeps the
 * progress, and a second person watching sees the same thing.
 *
 * Worth saying plainly in the copy: **the install arms nothing**. A fail2ban
 * that started banning the moment it appeared would be a nasty surprise, so the
 * user picks which jails to enable afterwards.
 */
export function InstallPrompt({ canManage, install = null }) {
  const t = useTranslations("fail2ban");
  const router = useRouter();
  // Covers only the gap between the click and the next read of the server —
  // after that `install.status` is the answer.
  const [starting, setStarting] = useState(false);

  const installing = install?.status === "installing" || starting;
  const failed = install?.status === "failed" && !starting;

  // Elapsed time is a clock read, so it cannot happen during render — it would
  // give a different answer every time React re-ran this. Same shape as
  // useIsMobile: the initial value comes through the tick the interval uses,
  // scheduled out of the effect body.
  const [slow, setSlow] = useState(false);
  const startedAtRaw = install?.started_at;

  useEffect(() => {
    const startedAt = parseApiDate(startedAtRaw);
    // One callback for every case, including "no longer installing" — a bare
    // setState in the effect body is a cascading render.
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
      // The next server read carries `install.status`, and the page refreshes
      // itself while that says installing.
      router.refresh();
    } catch (error) {
      const data = error.response?.data;
      setStarting(false);
      toast.error(
        [apiMessage(error, t("install.failed")), data?.reference].filter(Boolean).join(" · "),
      );
    }
  }

  const title = installing
    ? t("install.installingTitle")
    : failed
      ? t("install.failedTitle")
      : t("install.title");

  // The API renders the reason in the viewer's locale. Ours is only the
  // fallback for a failure it could not classify — inventing copy for someone
  // else's failure would be a guess.
  const body = installing
    ? t("install.installing")
    : failed
      ? (install?.reason_title ?? t("install.failedUnknown"))
      : t("install.body");

  return (
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
        {/* Small and last, like the error box: meaningless to most people, and
            the first thing support asks for. */}
        {failed && install?.reference ? (
          <p className="pt-1 font-mono text-xs text-muted-foreground">{install.reference}</p>
        ) : null}
      </div>

      {installing ? null : (
        <Button disabled={!canManage} onClick={start}>
          {failed ? t("install.retry") : t("install.action")}
        </Button>
      )}
      {!canManage && !installing ? (
        <p className="text-xs text-muted-foreground">{t("install.noPermission")}</p>
      ) : null}
    </div>
  );
}
