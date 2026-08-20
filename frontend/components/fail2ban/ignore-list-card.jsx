"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { X, Plus, UserCheck, ShieldCheck, TriangleAlert } from "lucide-react";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { isIpOrCidr } from "@/lib/validation/ip";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";

/**
 * Addresses that are never banned — and the one control that prevents the worst
 * outcome on this page: adding your own.
 *
 * A vertical list, not wrapping chips. In a sidebar column chips reflow into a
 * ragged block where nothing lines up and the remove buttons land wherever;
 * one address per row stays readable at any width and gives each entry a
 * predictable place to click.
 *
 * Saves the whole settings object because the backend rewrites the file as a
 * unit — sending only the list would drop the numbers the other card owns.
 */
export function IgnoreListCard({ settings, yourIp, canManage }) {
  const t = useTranslations("fail2ban");
  const router = useRouter();

  const [ips, setIps] = useState(settings.ignore_ips ?? []);
  const [draft, setDraft] = useState("");
  const [draftError, setDraftError] = useState(null);
  const [saving, setSaving] = useState(false);
  // Same shape as the ban rules card: the wait is the write plus the re-read,
  // and one signal has to cover both or the spinner lies about being finished.
  const [refreshing, startRefresh] = useTransition();
  const pending = saving || refreshing;
  // Taking your own address OFF this list is the same mistake the lockout
  // dialog exists to prevent, just approached from the other side.
  const [confirmRemoveSelf, setConfirmRemoveSelf] = useState(false);

  const saved = settings.ignore_ips ?? [];
  const dirty = JSON.stringify(saved) !== JSON.stringify(ips);
  const ipIgnored = Boolean(yourIp) && ips.includes(yourIp);

  function add(value) {
    const ip = value.trim();
    if (!ip) return;
    // These lines go into the firewall config verbatim. A typo here doesn't
    // fail loudly, it just protects nobody, so it gets caught in the field.
    if (!isIpOrCidr(ip)) {
      setDraftError(t("settings.invalidIp"));
      return;
    }
    setDraftError(null);
    if (ips.includes(ip)) {
      setDraft("");
      return;
    }
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
      toast.success(t("settings.ignoreSaved"));
      startRefresh(() => router.refresh());
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
          {/* The safety prompt sits above the list, not beside the input: if your
              own address isn't here, that is the most important fact on the page
              and it should read as advice rather than a form control. */}
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
                  <UserCheck className="size-4" />
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
                      <span className="rounded bg-success/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-success">
                        {t("settings.you")}
                      </span>
                    ) : null}
                    {canManage ? (
                      <button
                        type="button"
                        onClick={() => remove(ip)}
                        // A removal made mid-save is silently undone by the
                        // refresh that follows it.
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
            <ReasonTooltip
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
                <Plus className="size-4" />
              </Button>
            </ReasonTooltip>
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
            onDiscard={() => setIps(saved)}
            saveLabel={t("settings.save")}
            savingNote={t("settings.savingNote")}
          />
        </div>
  
        {/* The mirror image of the lockout dialog: that one stops you switching
            on a jail that could ban you, this one stops you removing the reason
            it cannot. Same outcome, opposite direction. */}
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
