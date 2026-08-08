"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Ban, Loader2, ShieldAlert, ShieldCheck, ShieldOff } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  bannedAddresses,
  isIpAddress,
  jailKind,
  jailTotals,
} from "@/lib/schemas/application-fail2ban";
import {
  banApplicationIp,
  unbanApplicationIp,
  updateApplicationFail2ban,
} from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";

/** Jail kinds this screen has a plain-language name for. */
const KNOWN_JAILS = new Set(["generic", "wplogin"]);

/**
 * One site's brute-force protection.
 *
 * The page is designed around the state it is usually in — nothing banned,
 * nothing happening — and around the reason people actually open it, which is
 * not auditing counters: it is being locked out of their own site. So the
 * first thing rendered is whether THIS visitor is the one who is banned, and
 * the banned table only exists when there is something in it.
 *
 * Deliberately not a copy of the server-level Fail2ban screen: there, each
 * jail toggles on its own. Here a single column drives both of this site's
 * jails, so there is one switch and the jails are facts, not controls.
 */
export function Fail2banPanel({ appId, enabled, jails, viewerIp, canManage }) {
  const t = useTranslations("applications.fail2ban");
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [unbanning, setUnbanning] = useState(null);
  const [confirmOff, setConfirmOff] = useState(false);
  const [draft, setDraft] = useState("");
  const [banError, setBanError] = useState(null);

  const banned = bannedAddresses(jails);
  const totals = jailTotals(jails);
  const viewerBanned = viewerIp ? banned.includes(viewerIp) : false;

  async function toggle(next) {
    if (!next) {
      setConfirmOff(true);
      return;
    }
    await setEnabled(true);
  }

  async function setEnabled(next) {
    setBusy(true);
    try {
      await updateApplicationFail2ban(appId, next);
      setConfirmOff(false);
      toast.success(next ? t("turnedOn") : t("turnedOff"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setBusy(false);
    }
  }

  async function unban(ip) {
    setUnbanning(ip);
    try {
      await unbanApplicationIp(appId, ip);
      toast.success(t("unbanned", { ip }));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("unbanFailed")));
    } finally {
      setUnbanning(null);
    }
  }

  async function ban() {
    const ip = draft.trim();
    if (!ip) return;
    if (!isIpAddress(ip)) {
      setBanError("ipAddress");
      return;
    }

    setBusy(true);
    try {
      await banApplicationIp(appId, ip);
      setDraft("");
      setBanError(null);
      toast.success(t("bannedIp", { ip }));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("banFailed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="max-w-3xl space-y-4">
      {/* Bans expire by themselves and new ones arrive unannounced, so a page
          rendered once is stale within the minute — but only while something
          is actually watching. */}
      {enabled ? <AutoRefresh intervalMs={10000} stopAfterMs={600000} /> : null}

      {/* First, loudest, and the only state where seconds matter: the person
          reading this is the one who is locked out. */}
      {viewerBanned ? (
        <Card className="gap-0 overflow-hidden border-destructive/40 py-0 shadow-sm">
          <CardContent className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-destructive/10">
              <ShieldAlert className="size-5 text-destructive" />
            </span>
            <div className="min-w-0 flex-1 space-y-1">
              <p className="font-medium">{t("lockedOut.title")}</p>
              <p className="text-sm text-muted-foreground">
                {t("lockedOut.body", { ip: viewerIp })}
              </p>
            </div>
            {canManage ? (
              <Button
                variant="destructive"
                onClick={() => unban(viewerIp)}
                disabled={unbanning === viewerIp}
                className="w-full sm:w-auto"
              >
                {unbanning === viewerIp ? <Loader2 className="size-4 animate-spin" /> : null}
                {t("lockedOut.action")}
              </Button>
            ) : null}
          </CardContent>
        </Card>
      ) : null}

      <Card className="gap-0 overflow-hidden py-0 shadow-sm">
        <CardContent className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start">
          <span
            className={cn(
              "flex size-10 shrink-0 items-center justify-center rounded-lg",
              enabled ? "bg-success/10" : "bg-muted",
            )}
          >
            {enabled ? (
              <ShieldCheck className="size-5 text-success" />
            ) : (
              <ShieldOff className="size-5 text-muted-foreground" />
            )}
          </span>

          <div className="min-w-0 flex-1 space-y-1">
            {/* The state in one line, in the words the server-level screen
                already uses — the same idea should not have two vocabularies. */}
            <p className="font-medium">
              {!enabled
                ? t("state.off")
                : totals.currentlyFailed > 0
                  ? t("state.failing", { count: totals.currentlyFailed })
                  : t("state.quiet")}
            </p>
            <p className="text-sm text-muted-foreground">
              {enabled ? t("state.onBody") : t("state.offBody")}
            </p>
          </div>

          {canManage ? (
            <Switch checked={enabled} onCheckedChange={toggle} disabled={busy} />
          ) : (
            <Badge variant="outline">{enabled ? t("state.onBadge") : t("state.offBadge")}</Badge>
          )}
        </CardContent>

        {enabled && jails.length > 0 ? (
          <div className="border-t px-5 py-3.5">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {t("watching")}
            </p>
            <ul className="mt-2 space-y-1.5">
              {jails.map((jail) => (
                <li key={jail.jail} className="flex items-center justify-between gap-3 text-sm">
                  <span className={cn(!jail.enabled && "text-muted-foreground")}>
                    {/* A jail we have no word for yet shows its raw name
                        rather than a missing-key crash — the backend can add a
                        third at any time. */}
                    {KNOWN_JAILS.has(jailKind(jail.jail))
                      ? t(`jails.${jailKind(jail.jail)}`)
                      : jail.jail}
                  </span>
                  {jail.enabled ? (
                    <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
                      {t("jailCounts", {
                        failed: jail.stats?.currently_failed ?? 0,
                        banned: jail.stats?.currently_banned ?? 0,
                      })}
                    </span>
                  ) : (
                    <span className="shrink-0 text-xs text-muted-foreground">
                      {t("jailInactive")}
                    </span>
                  )}
                </li>
              ))}
            </ul>

            {/* One sentence rather than four counters in a table: "is this
                doing anything" is a single question. */}
            {totals.totalFailed > 0 || totals.totalBanned > 0 ? (
              <p className="mt-3 border-t pt-3 text-xs text-muted-foreground">
                {t("totals", { failed: totals.totalFailed, banned: totals.totalBanned })}
              </p>
            ) : null}
          </div>
        ) : null}
      </Card>

      {enabled ? (
        <Card className="gap-0 overflow-hidden py-0 shadow-sm">
          <div className="border-b px-5 py-3.5">
            <h2 className="text-sm font-semibold tracking-tight">
              {t("banned.title", { count: banned.length })}
            </h2>
          </div>

          <CardContent className="p-0">
            {banned.length === 0 ? (
              // The normal, healthy state — one line that says so, not an
              // empty table implying something is missing.
              <p className="px-5 py-4 text-sm text-muted-foreground">{t("banned.empty")}</p>
            ) : (
              <ul className="divide-y">
                {banned.map((ip) => (
                  <li key={ip} className="flex items-center gap-3 px-5 py-3">
                    <span className="min-w-0 flex-1 truncate font-mono text-sm">
                      {ip}
                      {ip === viewerIp ? (
                        <Badge variant="destructive" className="ml-2 font-normal">
                          {t("banned.you")}
                        </Badge>
                      ) : null}
                    </span>
                    {canManage ? (
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => unban(ip)}
                        disabled={unbanning === ip}
                      >
                        {unbanning === ip ? <Loader2 className="size-3.5 animate-spin" /> : null}
                        {t("banned.unban")}
                      </Button>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>

          {/* Secondary on purpose: people come here to let an address back in
              far more often than to shut one out by hand. */}
          {canManage ? (
            <div className="flex flex-col gap-2 border-t px-5 py-3.5 sm:flex-row sm:items-center">
              <div className="flex flex-1 gap-2">
                <Input
                  value={draft}
                  onChange={(event) => {
                    setDraft(event.target.value);
                    if (banError) setBanError(null);
                  }}
                  onKeyDown={(event) => {
                    if (event.key !== "Enter") return;
                    event.preventDefault();
                    ban();
                  }}
                  placeholder={t("ban.placeholder")}
                  spellCheck={false}
                  autoComplete="off"
                  disabled={busy}
                  aria-invalid={Boolean(banError)}
                  className="h-8 font-mono text-xs sm:max-w-56"
                />
                <Button type="button" variant="outline" size="sm" onClick={ban} disabled={busy}>
                  <Ban className="size-3.5" />
                  {t("ban.action")}
                </Button>
              </div>
              {banError ? (
                <p className="text-xs text-destructive">{t(`ban.errors.${banError}`)}</p>
              ) : null}
            </div>
          ) : null}
        </Card>
      ) : null}

      <ConfirmDialog
        open={confirmOff}
        onOpenChange={setConfirmOff}
        icon={ShieldOff}
        tone="warning"
        title={t("confirmOff.title")}
        description={t("confirmOff.body")}
        confirmLabel={t("confirmOff.confirm")}
        pending={busy}
        onConfirm={() => setEnabled(false)}
      />
    </div>
  );
}
