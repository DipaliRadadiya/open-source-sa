"use client";

import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { History, Loader2, ShieldCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { formatBytes } from "@/lib/format/bytes";
import { BACKUP_IN_FLIGHT, restorableTypes } from "@/lib/schemas/backup";
import { startRestore } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
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
 * Restoring a site — the only destructive action in the panel.
 *
 * Deliberately effortful, but not obstructive: whoever opens this is usually
 * having a bad day already. The order of the dialog is the order of the
 * questions they have — what am I going back to, what exactly gets replaced,
 * can I undo it, and only then the thing that costs effort.
 *
 * Every guard the backend enforces is mirrored here, because being refused
 * *after* typing your own domain is the worst possible moment to meet a
 * validation error.
 *
 * Callers MUST pass `key={backup?.id}` so switching backups remounts this
 * instead of carrying state across. A dialog opened from its own row never
 * fires `onOpenChange` on the way in, so a domain typed for one site would
 * otherwise still be sitting in the box when the next one opens — pre-filling
 * the safeguard for the wrong site.
 */
export function RestoreDialog({ backup, open, onOpenChange, onStarted }) {
  const t = useTranslations("backups.restore");
  const format = useFormatter();
  const [type, setType] = useState(backup?.type ?? "full");
  const [confirm, setConfirm] = useState("");
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState(null);

  const domain = backup?.application_domain ?? "";
  const allowed = restorableTypes(backup?.type);

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

  // Ordered by what the user can do about it.
  const matches = confirm.trim() === domain && domain !== "";
  const blocker = !matches ? t("typeToUnlock") : null;

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      {/* Width needs `!`, and `size` must stay `default`.
          The base sets `data-[size=default]:sm:max-w-sm` — an attribute
          selector that outranks a plain `sm:max-w-2xl`, so every width set
          here silently lost and the dialog stayed 384px. Opting out with
          `size="custom"` did free the width, but `size` also gates the
          HEADER: it only left-aligns under
          `sm:group-data-[size=default]`, so the title and icon fell back to
          centred and this became the one confirmation in the panel that did
          not match the others. `!` wins the width without leaving the group. */}
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
          {/* What is about to happen, as one sentence with the moment in it.
              "Are you sure?" tells nobody anything. */}
          <AlertDialogDescription className="pt-1">
            {t("descriptionShort")}
          </AlertDialogDescription>
        </AlertDialogHeader>

        <div className="space-y-4">
          {/* Which backup, as labelled facts rather than a date buried in
              prose. This is the one thing the reader must get right, and a
              timestamp inside a sentence is the easiest thing to skim past. */}
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
          {/* Only offered when the archive holds more than one thing. A single
              radio option is a decision with no alternative. */}
          {allowed.length > 1 ? (
            <div className="space-y-1.5">
              <Label>{t("whatToRestore")}</Label>
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

          {/* The single most reassuring true sentence in the feature, and it
              belongs BEFORE the commitment, not in the success screen. */}
          <div className="flex items-start gap-2.5 rounded-lg border border-success/40 bg-success/10 p-3 text-sm">
            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-success" />
            <p>{t("safetyPromise")}</p>
          </div>

          <div className="space-y-1.5">
            {/* The domain is shown in the label, never prefilled — the typing
                IS the safeguard. Setting it apart in mono makes it something
                to copy by eye rather than a phrase to skim. */}
            <div className="flex flex-wrap items-center gap-1.5">
              <Label htmlFor="restore-confirm">{t("confirmLabelPlain")}</Label>
              <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.8125rem] font-medium">
                {domain}
              </span>
              {/* Typing is the safeguard, but re-typing a long domain by eye is
                  a transcription test, not a decision. Copy lets someone who
                  has already decided get on with it. */}
              <CopyButton value={domain} label={t("copyDomain")} />
            </div>
            <Input
              id="restore-confirm"
              value={confirm}
              onChange={(event) => setConfirm(event.target.value)}
              disabled={pending}
              autoComplete="off"
              spellCheck={false}
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
              // `variant`, not hand-rolled classes. The same colours by a
              // different route drift the moment the token behind them moves,
              // and this button must look exactly like every other destructive
              // confirm in the panel.
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
 * Why this backup cannot be restored, or null when it can.
 *
 * Mirrors `RestoreBackupRequest::withValidator()` in order. Exported so the
 * row can disable its own menu item and say why, rather than opening a dialog
 * that was always going to be refused.
 */
export function restoreBlocker(backup, t, restoreInFlight = false) {
  // A backup still being written is not "unverified" in any sense the user
  // would recognise — it simply has not finished. Saying "never verified" about
  // a run in progress is true to the schema and misleading to a person, which
  // is the worst combination.
  if (BACKUP_IN_FLIGHT.includes(backup.status)) return t("blocked.inFlight");
  if (backup.status === "failed") return t("blocked.failed");
  if (backup.status !== "verified") return t("blocked.unverified");
  if (!backup.application_domain) return t("blocked.noApplication");
  if (restoreInFlight) return t("blocked.alreadyRunning");
  return null;
}
