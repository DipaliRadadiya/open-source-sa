import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { History, Loader2, ShieldCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { formatBytes } from "@/lib/format/bytes";
import { BACKUP_IN_FLIGHT, restorableTypes } from "@/lib/schemas/backup";
import { startRestore } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Input } from "@/components/ui/input";
import { CopyButton } from "@/components/ui/copy-button";
import { Label } from "@/components/ui/label";
import { ChoiceField } from "@/components/ui/choice-field";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

/**
 * Restoring a site, the only destructive action in the panel. Every backend
 * guard is mirrored here so nothing is refused after the domain is typed.
 *
 * Callers MUST pass `key={backup?.id}`: a dialog opened from its own row
 * skips `onOpenChange`, so without a remount the typed domain would carry
 * over to the next site.
 */
export function RestoreDialog({ backup, open, onOpenChange, onStarted }) {
  const t = useTranslations("backups.restore");
  const format = useFormatter();
  const allowed = backup?.files_only
    ? restorableTypes(backup?.type).filter((value) => value === "filesystem")
    : restorableTypes(backup?.type);
  const [type, setType] = useState(
    allowed.includes(backup?.preferred_type)
      ? backup.preferred_type
      : allowed.includes(backup?.type)
        ? backup.type
        : (allowed[0] ?? backup?.type ?? "full"),
  );
  const [confirm, setConfirm] = useState("");
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState(null);

  const domain = backup?.application_domain ?? "";

  async function onConfirm() {
    setPending(true);
    setFailure(null);
    try {
      const response = await startRestore(backup.id, { type, confirm: confirm.trim() });
      onStarted?.(response.data?.restore ?? null);
      onOpenChange?.(false);
    } catch (error) {
      setFailure(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  const matches = confirm.trim() === domain && domain !== "";
  const blocker = !matches ? t("typeToUnlock") : null;

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      {/* Width needs `!`, and `size` must stay `default`: the base's
          `data-[size=default]:sm:max-w-sm` outranks a plain `sm:max-w-2xl`,
          and `size` also controls the header's left alignment. */}
      <AlertDialogContent className="sm:!max-w-2xl">
        <AlertDialogHeader>
          <div className="flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-destructive/10 text-destructive">
              <History className="size-5" />
            </span>
            <AlertDialogTitle>
              {t("title", { name: backup?.application_name ?? domain })}
            </AlertDialogTitle>
          </div>
          <AlertDialogDescription className="pt-1">
            {t("descriptionShort")}
          </AlertDialogDescription>
        </AlertDialogHeader>

        <div className="space-y-4">
          {/* Which backup, as labelled facts rather than a date inside prose. */}
          <dl className="grid grid-cols-3 gap-x-4 gap-y-3 rounded-lg border bg-muted/40 p-3">
            <Fact label={t("facts.taken")} value={backup?.created_at_human ?? ""} />
            <Fact label={t("facts.type")} value={backup?.type_title ?? backup?.type ?? ""} />
            <Fact
              label={t("facts.size")}
              value={
                backup?.size_bytes
                  ? formatBytes(backup.size_bytes, format)
                  : t("facts.sizeUnknown")
              }
            />
          </dl>
          {/* Only when the archive holds more than one thing. */}
          {allowed.length > 1 ? (
            <div className="space-y-1.5">
              <Label hint={t("whatToRestoreHint")}>{t("whatToRestore")}</Label>
              <ChoiceField
                value={type}
                onChange={setType}
                disabled={pending}
                options={allowed.map((value) => ({
                  value,
                  label: t(`types.${value}.label`),
                  hint: t(`types.${value}.hint`),
                }))}
              />
            </div>
          ) : null}

          {/* The safety-copy promise belongs before the commitment. */}
          <div className="flex items-start gap-2.5 rounded-lg border border-success/40 bg-success/10 p-3 text-sm">
            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-success" />
            <p>{t("safetyPromise")}</p>
          </div>

          <div className="space-y-1.5">
            {/* The domain is shown in the label, never prefilled: typing it IS the safeguard. */}
            <div className="flex flex-wrap items-center gap-1.5">
              <Label htmlFor="restore-confirm">{t("confirmLabelPlain")}</Label>
              <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.8125rem] font-medium">
                {domain}
              </span>
              <CopyButton value={domain} label={t("copyDomain")} />
            </div>
            <Input
              id="restore-confirm"
              value={confirm}
              onChange={(event) => setConfirm(event.target.value)}
              disabled={pending}
              autoComplete="off"
              spellCheck={false}
              placeholder={domain}
              className={cn("font-mono", matches && "border-success focus-visible:border-success")}
            />
          </div>

          {failure ? (
            <p className="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
              {failure}
            </p>
          ) : null}
        </div>

        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>{t("cancel")}</AlertDialogCancel>
          <ReasonTooltip reason={blocker}>
            <AlertDialogAction
              onClick={(event) => {
                // The dialog must not close on click — it closes when the
                // request comes back, or stays open showing why it did not.
                event.preventDefault();
                onConfirm();
              }}
              disabled={Boolean(blocker) || pending}
              // Use the variant, not hand-rolled classes, so it matches every destructive confirm.
              variant="destructive"
            >
              {pending ? <Loader2 className="size-4 animate-spin" /> : null}
              {pending ? t("starting") : t("submit")}
            </AlertDialogAction>
          </ReasonTooltip>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

function Fact({ label, value }) {
  return (
    <div className="min-w-0">
      <dt className="text-[0.6875rem] font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </dt>
      <dd className="mt-0.5 text-sm font-medium tabular-nums">{value}</dd>
    </div>
  );
}

/**
 * Why this backup cannot be restored, or null when it can. Mirrors
 * `RestoreBackupRequest::withValidator()` in order; exported so a row can
 * disable its own menu item.
 */
export function restoreBlocker(backup, t, restoreInFlight = false) {
  // A backup still being written has simply not finished; don't call it "unverified".
  if (BACKUP_IN_FLIGHT.includes(backup.status)) return t("blocked.inFlight");
  if (backup.status === "failed") return t("blocked.failed");
  if (backup.status !== "verified") return t("blocked.unverified");
  if (!backup.application_domain) return t("blocked.noApplication");
  if (restoreInFlight) return t("blocked.alreadyRunning");
  return null;
}
