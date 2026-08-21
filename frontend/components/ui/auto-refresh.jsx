"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * Re-runs the server component on an interval so a page rendered from a
 * snapshot doesn't quietly go stale.
 *
 * Only while the tab is visible: a background tab polling an API is somebody
 * else's server doing work for a screen nobody is looking at. Coming back to
 * the tab refreshes immediately rather than waiting out the interval, because
 * the moment you look is exactly when the numbers matter.
 *
 * `router.refresh()` re-renders the tree in place rather than remounting it, so
 * half-typed form state in client children survives.
 *
 * Giving up is ANNOUNCED. This used to `clearInterval` and return null, so a
 * page that said "Installing…" or "Backing up…" kept saying it for as long as
 * the tab stayed open, with nothing behind it and no way to tell. The restore
 * screen had already learned that lesson in its own poller — "a spinner that
 * has silently stopped spinning is the worst version of this screen: it still
 * claims work is happening" — while the eight screens using this one kept the
 * silent version.
 */
export function AutoRefresh({ intervalMs = 10000, stopAfterMs = null }) {
  const router = useRouter();
  const t = useTranslations("common.autoRefresh");
  // Bumped by "Check again", which restarts the effect and so restarts both
  // the interval and the give-up timer.
  const [round, setRound] = useState(0);
  const [stopped, setStopped] = useState(false);

  useEffect(() => {
    // No setState in the effect body — that is a cascading render, and the
    // lint rule is right to refuse it. `stopped` is cleared by the only thing
    // that restarts this: the button below.
    const tick = () => {
      if (!document.hidden) router.refresh();
    };

    const id = setInterval(tick, intervalMs);
    document.addEventListener("visibilitychange", tick);

    // Some callers poll because a job is running, and a job that never lands —
    // a worker that died mid-backup, say — would otherwise leave the page
    // polling for as long as the tab is open.
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
    // Restarts the effect, and with it both the interval and the give-up timer.
    setRound((n) => n + 1);
  }, [router]);

  if (!stopped) return null;

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
