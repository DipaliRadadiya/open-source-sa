"use client";

import { useBrowserIp } from "@/components/network/browser-ip";
import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { X, Plus, UserCheck, ShieldCheck, TriangleAlert } from "lucide-react";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { isIpOrCidr } from "@/lib/validation/ip";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { ActionIcon } from "@/components/ui/action-icon";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";

// Matches `ignore_ips => array|max:100` in UpdateFail2banRequest.
const MAX_IGNORE_IPS = 100;

/**
 * Addresses that are never banned. Saves the whole settings object because the
 * backend rewrites the file as a unit; sending only the list would drop the
 * ban rules.
 */
export function IgnoreListCard({ settings, canManage }) {
  const yourIp = useBrowserIp();
  const t = useTranslations("fail2ban");
  const { refreshAndWait } = useRefresh();

  const saved = settings.ignore_ips ?? [];
  // Follows the server until edited; an edit holds only while the server still
  // has the list it was based on, so a stale copy never overwrites a newer one.
  const [edited, setEdited] = useState(null);
  const sameList = (a, b) => JSON.stringify(a) === JSON.stringify(b);
  const live = edited && sameList(edited.base, saved) ? edited.ips : null;
  const ips = live ?? saved;
  const setIps = (update) => setEdited({ ips: update(ips), base: saved });
  const [draft, setDraft] = useState("");
  const [draftError, setDraftError] = useState(null);
  const [saving, setSaving] = useState(false);
  const pending = saving;
  // Removing your own address needs confirmation (lockout risk).
  const [confirmRemoveSelf, setConfirmRemoveSelf] = useState(false);

  const dirty = JSON.stringify(saved) !== JSON.stringify(ips);
  const ipIgnored = Boolean(yourIp) && ips.includes(yourIp);

  function add(value) {
    const ip = value.trim();
    if (!ip) return;
    // Written to the config verbatim; a typo would silently protect nobody.
    if (!isIpOrCidr(ip)) {
      setDraftError(t("settings.invalidIp"));
      return;
    }
    if (ips.includes(ip)) {
      setDraftError(null);
      setDraft("");
      return;
    }
    // Mirrors the API cap so the 101st entry fails here, not on Save.
    if (ips.length >= MAX_IGNORE_IPS) {
      setDraftError(t("settings.ignoreLimit", { max: MAX_IGNORE_IPS }));
      return;
    }
    setDraftError(null);
    setIps((prev) => [...prev, ip]);
    setDraft("");
  }

  function remove(ip) {
    if (ip === yourIp) {
      setConfirmRemoveSelf(true);
      return;
    }
    setIps((prev) => prev.filter((v) => v !== ip));
  }

  async function save() {
    setSaving(true);
    try {
      await updateFail2ban({
        bantime: settings.bantime,
        findtime: settings.findtime,
        maxretry: settings.maxretry,
        ignore_ips: ips,
      });
      await refreshAndWait();
      toast.success(t("settings.ignoreSaved"));
    } catch (error) {
      toast.error(
        apiMessage(error, t("settings.failed")),
      );
    } finally {
      setSaving(false);
    }
  }

  const saveReason = !canManage
    ? t("disabled.noPermission")
    : !dirty
      ? t("disabled.noChanges")
      : null;

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Card className="h-full">
        <CardHeader>
          <CardTitle className="text-base font-semibold">{t("settings.ignore")}</CardTitle>
          <CardDescription>{t("settings.ignoreHint")}</CardDescription>
        </CardHeader>
  
        <CardContent className="space-y-3">
          {/* Above the list: a missing own IP is the most important fact here. */}
          {yourIp && !ipIgnored ? (
            <div className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/5 p-3">
              <ShieldCheck className="mt-0.5 size-4 shrink-0 text-warning" />
              <div className="min-w-0 space-y-2">
                <p className="text-xs leading-relaxed">
                  {t("settings.yourIpMissing", { ip: yourIp })}
                </p>
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={!canManage || pending}
                  onClick={() => add(yourIp)}
                >
                  <ActionIcon icon={UserCheck} pending={pending} />
                  {t("settings.addMine")}
                </Button>
              </div>
            </div>
          ) : null}
  
          <ul className="divide-y rounded-lg border">
            {ips.length === 0 ? (
              <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                {t("settings.ignoreEmpty")}
              </li>
            ) : (
              ips.map((ip) => (
                <li key={ip} className="flex items-center justify-between gap-2 px-3 py-2">
                  <span className="min-w-0 truncate font-mono text-sm">{ip}</span>
                  <div className="flex shrink-0 items-center gap-1">
                    {ip === yourIp ? (
                      <span className="rounded bg-success/10 px-1.5 py-0.5 text-xs font-medium uppercase tracking-wide text-success">
                        {t("settings.you")}
                      </span>
                    ) : null}
                    {canManage ? (
                      <button
                        type="button"
                        onClick={() => remove(ip)}
                        // A removal mid-save would be undone by the following refresh.
                        disabled={pending}
                        aria-label={t("settings.removeIp", { ip })}
                        className="rounded p-1 text-muted-foreground hover:text-destructive disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                      >
                        <X className="size-3.5" />
                      </button>
                    ) : null}
                  </div>
                </li>
              ))
            )}
          </ul>
  
          <div className="flex items-center gap-2">
            <Input
              value={draft}
              onChange={(e) => {
                setDraft(e.target.value);
                if (draftError) setDraftError(null);
              }}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  add(draft);
                }
              }}
              placeholder={t("settings.ipPlaceholder")}
              disabled={!canManage || pending}
              className="font-mono"
              autoComplete="off"
              spellCheck={false}
              aria-invalid={Boolean(draftError)}
              aria-describedby={draftError ? "f2b-ignore-error" : undefined}
            />
            <IconTooltip
              label={t("settings.addIp")}
              reason={
                !canManage
                  ? t("disabled.noPermission")
                  : !draft.trim()
                    ? t("disabled.enterIp")
                    : null
              }
            >
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-9 shrink-0"
                disabled={!canManage || !draft.trim() || pending}
                onClick={() => add(draft)}
                aria-label={t("settings.addIp")}
              >
                <ActionIcon icon={Plus} pending={pending} />
              </Button>
            </IconTooltip>
          </div>
  
          {draftError ? (
            <p id="f2b-ignore-error" role="alert" className="text-xs text-destructive">
              {draftError}
            </p>
          ) : null}
        </CardContent>
  
        <div className="mt-auto">
          <CardSaveFooter
            saving={pending}
            dirty={dirty}
            saveReason={saveReason}
            onSave={save}
            onDiscard={() => setEdited(null)}
            saveLabel={t("settings.save")}
            savingNote={t("settings.savingNote")}
          />
        </div>
  
        <ConfirmDialog
          open={confirmRemoveSelf}
          onOpenChange={setConfirmRemoveSelf}
          icon={TriangleAlert}
          tone="warning"
          confirmVariant="destructive"
          title={t("settings.removeYouTitle")}
          description={t("settings.removeYouDescription", { ip: yourIp })}
          cancelLabel={t("settings.cancel")}
          confirmLabel={t("settings.removeYouConfirm")}
          onConfirm={() => {
            setIps((prev) => prev.filter((v) => v !== yourIp));
            setConfirmRemoveSelf(false);
          }}
        />
      </Card>
    </DisabledReasonProvider>
  );
}
