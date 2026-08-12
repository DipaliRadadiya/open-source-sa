"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ShieldPlus, Loader2 } from "lucide-react";
import { installFail2ban, getFail2ban } from "@/lib/api/fail2ban";
import { fail2banResponseSchema } from "@/lib/schemas/fail2ban";
import { Button } from "@/components/ui/button";
import { apiMessage } from "@/lib/api/error-message";

const POLL_MS = 4000;
// apt on a small box is slow but not this slow. Past this we stop claiming
// progress — an unbounded "Installing…" is a promise we have no evidence for.
const GIVE_UP_AFTER_MS = 3 * 60 * 1000;

/**
 * Not installed is a normal state on a fresh server, so this is an invitation
 * rather than an error: what fail2ban does, and one button.
 *
 * The install is queued (apt is slow), so we poll until it lands. Worth saying
 * plainly in the copy: **the install arms nothing**. A fail2ban that started
 * banning the moment it appeared would be a nasty surprise, so the user picks
 * which jails to enable afterwards.
 */
export function InstallPrompt({ canManage }) {
  const t = useTranslations("fail2ban");
  const router = useRouter();
  const [installing, setInstalling] = useState(false);
  const [stalled, setStalled] = useState(false);
  const [checking, setChecking] = useState(false);

  useEffect(() => {
    if (!installing) return undefined;

    let active = true;
    const startedAt = Date.now();

    const id = setInterval(async () => {
      if (Date.now() - startedAt > GIVE_UP_AFTER_MS) {
        // Not "it failed" — we genuinely don't know. Say that, and hand back
        // the one action that can settle it.
        if (active) {
          setInstalling(false);
          setStalled(true);
        }
        return;
      }
      if (document.hidden) return;
      try {
        const { data } = await getFail2ban();
        const parsed = fail2banResponseSchema.safeParse(data);
        if (!active || !parsed.success) return;
        if (parsed.data.fail2ban.installed) {
          setInstalling(false);
          toast.success(t("install.done"));
          router.refresh();
        }
      } catch {
        // Keep waiting — apt takes a while and a blip mid-install is not a
        // failed install.
      }
    }, POLL_MS);

    return () => {
      active = false;
      clearInterval(id);
    };
  }, [installing, router, t]);

  /**
   * A one-off check, with a visible result either way.
   *
   * `router.refresh()` alone re-rendered the same "not installed" screen, so a
   * working button looked like a dead one. An action that can legitimately
   * change nothing on screen has to say what it found.
   */
  async function checkNow() {
    setChecking(true);
    try {
      const { data } = await getFail2ban();
      const parsed = fail2banResponseSchema.safeParse(data);
      if (parsed.success && parsed.data.fail2ban.installed) {
        toast.success(t("install.done"));
        router.refresh();
        return;
      }
      toast.info(t("install.stillNotInstalled"));
    } catch (error) {
      const data = error.response?.data;
      toast.error(
        [apiMessage(error, t("install.checkFailed")), data?.reference]
          .filter(Boolean)
          .join(" · "),
      );
    } finally {
      setChecking(false);
    }
  }

  async function start() {
    setStalled(false);
    setInstalling(true);
    try {
      await installFail2ban();
      toast.info(t("install.started"));
    } catch (error) {
      const data = error.response?.data;
      setInstalling(false);
      toast.error(
        [apiMessage(error, t("install.failed")), data?.reference].filter(Boolean).join(" · "),
      );
    }
  }

  return (
    <div className="flex flex-col items-center gap-4 rounded-xl border border-dashed py-16 text-center">
      {/* Once install is under way the card describes THAT, rather than leaving
          "Fail2ban is not installed" and the case for installing it above a
          spinner that says the opposite. */}
      <span className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
        {installing ? (
          <Loader2 className="size-5 animate-spin" />
        ) : (
          <ShieldPlus className="size-5" />
        )}
      </span>
      <div className="space-y-1">
        <p className="font-medium">
          {installing ? t("install.installingTitle") : t("install.title")}
        </p>
        <p className="mx-auto max-w-md text-sm leading-relaxed text-muted-foreground">
          {installing ? t("install.installing") : t("install.body")}
        </p>
      </div>

      {installing ? null : (
        <div className="flex flex-col items-center gap-3">
          {stalled ? (
            <p role="status" className="max-w-md text-sm text-warning">
              {t("install.stalled")}
            </p>
          ) : null}
          <div className="flex flex-wrap items-center justify-center gap-2">
            {stalled ? (
              <Button variant="outline" onClick={checkNow} disabled={checking}>
                {checking ? <Loader2 className="size-4 animate-spin" /> : null}
                {checking ? t("install.checking") : t("install.checkAgain")}
              </Button>
            ) : null}
            <Button
              variant={stalled ? "ghost" : "default"}
              disabled={!canManage || checking}
              onClick={start}
            >
              {canManage
                ? stalled
                  ? t("install.retry")
                  : t("install.action")
                : t("install.noPermission")}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
