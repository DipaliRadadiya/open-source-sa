"use client";

import { useBrowserIp } from "@/components/network/browser-ip";
import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { X, UserCheck, ShieldCheck, TriangleAlert } from "lucide-react";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { isIpOrCidr } from "@/lib/validation/ip";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { ActionIcon } from "@/components/ui/action-icon";
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

// Saves the whole settings object: the backend rewrites the file as a unit, so
// sending only the list would drop the ban rules.
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
      {/* The left editor sets the desktop row height; only this list may shrink. */}
      <Card className="@container/ignore-list min-w-0 gap-0 py-0 xl:@4xl/fail2ban-rules:h-0 xl:@4xl/fail2ban-rules:min-h-full">
        <CardHeader className="flex-none border-b px-5 py-4">
          <CardTitle className="flex flex-wrap items-center gap-2 text-base font-semibold">
            {t("settings.ignore")}
            <Badge variant="outline" className="font-normal">{t("settings.entriesCount", { count: ips.length })}</Badge>
          </CardTitle>
          <CardDescription>{t("settings.ignoreHint")}</CardDescription>
        </CardHeader>

        <CardContent className="flex min-h-0 flex-1 flex-col gap-3 px-5 py-4">
          {/* Above the list: a missing own IP is the most important fact here. */}
          {yourIp && !ipIgnored ? (
            <div data-slot="caution" className="flex flex-none items-start gap-2 rounded-lg border border-warning/30 bg-warning/5 p-2.5">
              <ShieldCheck className="mt-0.5 size-4 shrink-0 text-warning" />
              <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-2">
                <p className="min-w-0 flex-1 basis-36 [overflow-wrap:anywhere] text-xs leading-relaxed">
                  {t("settings.yourIpMissing", { ip: yourIp })}
                </p>
                <Button
                  size="sm"
                  variant="outline"
                  className="h-auto max-w-full py-1.5 text-xs whitespace-normal"
                  aria-label={t("settings.addMine")}
                  disabled={!canManage || pending}
                  onClick={() => add(yourIp)}
                >
                  <ActionIcon icon={UserCheck} pending={pending} />
                  {t("settings.addIp")}
                </Button>
              </div>
            </div>
          ) : null}

          <div className="flex-none space-y-2">
            <Label htmlFor="f2b-ignore-address">{t("settings.inputLabel")}</Label>
            <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2">
              <Input
                id="f2b-ignore-address"
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
                placeholder={t("lockout.ipPlaceholder")}
                disabled={!canManage || pending}
                className="min-w-0 font-mono"
                autoComplete="off"
                spellCheck={false}
                aria-invalid={Boolean(draftError)}
                aria-describedby={draftError ? "f2b-ignore-examples f2b-ignore-hint f2b-ignore-error" : "f2b-ignore-examples f2b-ignore-hint"}
              />
              <ReasonTooltip
                className="inline-flex shrink-0"
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
                  className="shrink-0"
                  disabled={!canManage || !draft.trim() || pending}
                  onClick={() => add(draft)}
                >
                  {t("settings.addIp")}
                </Button>
              </ReasonTooltip>
            </div>
            <div className="space-y-1 text-xs leading-relaxed text-muted-foreground">
              <p id="f2b-ignore-examples">{t("settings.ipPlaceholder")}</p>
              <p id="f2b-ignore-hint">{t("settings.ignoreDraftHint")}</p>
            </div>
            {draftError ? (
              <p id="f2b-ignore-error" role="alert" className="text-xs text-destructive">
                {draftError}
              </p>
            ) : null}
          </div>

          {/* Focusable native scrolling keeps warning, entry controls and Save outside.
              Side by side it shrinks to scroll but never grows: grown to the card's height,
              its last row looked tall (Krishna, 15 Oct). */}
          <ul
            tabIndex={0}
            aria-label={t("settings.ignore")}
            className="min-h-0 max-h-72 overflow-y-auto overscroll-contain rounded-lg border divide-y focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring xl:@4xl/fail2ban-rules:max-h-none"
          >
            {ips.length === 0 ? (
              <li className="px-3 py-4 text-sm text-muted-foreground">
                {t("settings.ignoreEmpty")}
              </li>
            ) : (
              ips.map((ip) => (
                <li key={ip} className="flex items-center justify-between gap-2 px-3 py-2">
                  <span className="min-w-0 break-all font-mono text-sm">{ip}</span>
                  <div className="flex shrink-0 items-center gap-1">
                    {ip === yourIp ? (
                      <Badge variant="success" className="font-normal">
                        {t("settings.you")}
                      </Badge>
                    ) : null}
                    {canManage ? (
                      <Tooltip>
                        <TooltipTrigger asChild>
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
                        </TooltipTrigger>
                        <TooltipContent>{t("settings.removeIp", { ip })}</TooltipContent>
                      </Tooltip>
                    ) : null}
                  </div>
                </li>
              ))
            )}
          </ul>

        </CardContent>

        <div className="flex-none">
          <CardSaveFooter
            saving={pending}
            dirty={dirty}
            saveReason={saveReason}
            onSave={save}
            onDiscard={() => setEdited(null)}
            saveLabel={t("settings.saveAddresses")}
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
