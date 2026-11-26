import { useState } from "react";
import { useFormatter } from "next-intl";
import { History, Loader2 } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { restorableTypes } from "@/lib/schemas/backup";
import { startRestore } from "@/lib/api/backups";
import { restoreDatabaseBackup } from "@/lib/api/database-backups";
import { useSubjectTranslations } from "@/hooks/use-subject-translations";
import { apiMessage } from "@/lib/api/error-message";
import { TypeToConfirm } from "@/components/ui/type-to-confirm";
import { Label } from "@/components/ui/label";
import { ChoiceField } from "@/components/ui/choice-field";
import { Caution } from "@/components/ui/caution";
import { SafetyCopySwitch } from "@/components/backups/safety-copy-switch";
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

// Callers must pass key={backup?.id}: opening from a row skips onOpenChange, so the typed domain would carry over.
export function RestoreDialog({ backup, open, onOpenChange, onStarted, subject = "application", database = null }) {
  const t = useSubjectTranslations("backups.restore", "backups.database.restore", subject);
  const format = useFormatter();
  const isDatabase = subject === "database";
  const allowed = isDatabase
    ? []
    : backup?.files_only
    ? restorableTypes(backup?.type).filter((value) => value === "filesystem")
    : restorableTypes(backup?.type, backup?.has_database);
  const [type, setType] = useState(
    allowed.includes(backup?.preferred_type)
      ? backup.preferred_type
      : allowed.includes(backup?.type)
        ? backup.type
        : (allowed[0] ?? backup?.type ?? "full"),
  );
  const [confirm, setConfirm] = useState("");
  const [safetyCopy, setSafetyCopy] = useState(true);
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState(null);

  const domain = (isDatabase ? database?.name : backup?.application_domain) ?? "";

  async function onConfirm() {
    setPending(true);
    setFailure(null);
    try {
      const response = isDatabase
        ? await restoreDatabaseBackup(database.id, backup.id, confirm.trim(), safetyCopy)
        :
          await startRestore(
            backup.id,
            {
              ...(allowed.length ? { type } : {}),
              confirm: confirm.trim(),
              safety_copy: safetyCopy,
            },
          );
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
    <AlertDialog
      open={open}
      onOpenChange={(next) => {
        if (!next) setSafetyCopy(true);
        onOpenChange?.(next);
      }}
    >
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
          <dl className="grid grid-cols-3 gap-x-4 gap-y-3 rounded-lg border bg-muted/40 p-3">
            <Fact label={t("facts.taken")} value={backup?.created_at_human ?? ""} />
            {subject === "database" ? (
              <Fact label={t("facts.from")} value={backup?.database_name ?? ""} />
            ) : (
              <Fact
                label={t("facts.type")}
                value={
                  backup?.type === "full" && allowed.length === 1 && allowed[0] === "filesystem"
                    ? t("types.filesystem.label")
                    : (backup?.type_title ?? backup?.type ?? "")
                }
              />
            )}
            <Fact
              label={t("facts.size")}
              value={
                backup?.size_bytes
                  ? formatBytes(backup.size_bytes, format)
                  : t("facts.sizeUnknown")
              }
            />
          </dl>
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

          <Caution tone="destructive" size="md">
            <p>
              {t("overwriteWarning")}
              {!safetyCopy ? ` ${t("noCopyWarning")}` : null}
            </p>
          </Caution>

          <SafetyCopySwitch
            id="restore-safety-copy"
            checked={safetyCopy}
            onCheckedChange={setSafetyCopy}
            disabled={pending}
            label={t("safetyCopyLabel")}
            hint={t("safetyPromise")}
          />

          <TypeToConfirm
            id="restore-confirm"
            value={domain}
            copyLabel={t("copyDomain")}
            confirm={confirm}
            onChange={setConfirm}
            disabled={pending}
            matches={matches}
          />

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
                // Stays open until the request returns.
                event.preventDefault();
                onConfirm();
              }}
              disabled={Boolean(blocker) || pending}
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
      <dt className="text-[0.6875rem] font-medium text-muted-foreground">
        {label}
      </dt>
      <dd className="mt-0.5 text-sm font-medium tabular-nums">{value}</dd>
    </div>
  );
}

