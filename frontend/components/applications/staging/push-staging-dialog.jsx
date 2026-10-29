import { useState } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Archive,
  ArrowRight,
  ArrowUpFromLine,
  Database,
  ExternalLink,
  FileText,
  Layers,
  Loader2,
  TriangleAlert,
} from "lucide-react";
import { PUSH_MODES } from "@/lib/schemas/application-staging";

const MODE_ICONS = { files: FileText, database: Database, full: Layers };
import { pushApplicationStaging } from "@/lib/api/applications";
import { apiMessage, mayStillBeRunning } from "@/lib/api/error-message";
import { ChoiceField } from "@/components/ui/choice-field";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Caution } from "@/components/ui/caution";
import { SafetyCopySwitch } from "@/components/backups/safety-copy-switch";
import { TypeToConfirm } from "@/components/ui/type-to-confirm";
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

// rsync `--delete` overwrites production and the snapshot only restores on a failed
// push, so a typed domain is required. Callers MUST pass a `key` that changes on open.
export function PushStagingDialog({ appId, production, staging, open, onOpenChange, canBackUp = false }) {
  const t = useTranslations("applications.staging.pushDialog");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [mode, setMode] = useState("files");
  const [confirm, setConfirm] = useState("");
  const [pending, setPending] = useState(false);
  // On by default when it is possible: the push cannot be undone otherwise.
  const [backup, setBackup] = useState(canBackUp);
  // Only a push that replaces the database takes the copy; files are always saved for the swap.
  const [safetyCopy, setSafetyCopy] = useState(true);
  const replacesDatabase = mode === "database" || mode === "full";

  const domain = production?.domain ?? "";
  const matches = confirm.trim() === domain && domain !== "";
  const blocker = !mode ? t("pickMode") : !matches ? t("typeToUnlock") : null;

  async function push() {
    setPending(true);
    try {
      // 202 with a run (RC-16): it has only started. The page shows its progress
      // and the result; saying "pushed" here was a guess that could be wrong.
      await pushApplicationStaging(appId, mode, { backup: canBackUp && backup, safetyCopy: !replacesDatabase || safetyCopy });
      await refreshAndWait();
      onOpenChange(false);
      toast.info(t("started", { domain }));
    } catch (error) {
      // A retry would only be refused as "already running"; close and let the page catch up.
      if (mayStillBeRunning(error)) {
        onOpenChange(false);
        toast.warning(t("stillRunning", { domain }), { duration: 20000 });
        router.refresh();
        return;
      }
      toast.error(apiMessage(error, t("failed")));
      // Usually the copy was deleted elsewhere; refresh so the page shows it.
      router.refresh();
    } finally {
      setPending(false);
    }
  }

  return (
    <AlertDialog open={open} onOpenChange={pending ? undefined : onOpenChange}>
      <AlertDialogContent className="sm:!max-w-2xl">
        <AlertDialogHeader>
          <div className="flex min-w-0 items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-destructive/10 text-destructive">
              <ArrowUpFromLine className="size-5" />
            </span>
            <AlertDialogTitle>{t("title")}</AlertDialogTitle>
          </div>
          <AlertDialogDescription className="pt-1">
            <span className="font-mono break-words">{staging?.domain}</span>
            <ArrowRight className="mx-1.5 inline size-3.5 align-[-2px]" aria-hidden />
            <span className="font-mono font-medium break-words text-foreground">{domain}</span>
            {staging?.created_at_human ? (
              <span className="block pt-1 text-xs">{t("copyAge", { age: staging.created_at_human })}</span>
            ) : null}
          </AlertDialogDescription>
        </AlertDialogHeader>

        <div className="space-y-4">
          {/* No option can be undone, so a backup is offered first when it can be taken. */}
          {canBackUp ? (
            <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
              <Checkbox
                id="staging-push-backup"
                checked={backup}
                onCheckedChange={(value) => setBackup(value === true)}
                disabled={pending}
                className="mt-0.5"
              />
              <div className="space-y-1">
                <Label htmlFor="staging-push-backup" className="text-sm font-medium">{t("backupToggle")}</Label>
                <p className="text-xs leading-5 text-muted-foreground">{backup ? t("backupOn") : t("backupOff")}</p>
              </div>
            </div>
          ) : (
          <p className="flex items-start gap-2.5 text-sm">
            <Archive className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
            <span>
              {t.rich("backupFirst", {
                link: (chunks) => (
                  <a
                    href={`/applications/${appId}/backups`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 font-medium text-primary underline underline-offset-4"
                  >
                    {chunks}
                    <ExternalLink className="size-3.5" aria-hidden />
                  </a>
                ),
              })}
            </span>
          </p>
          )}

          {/* One line per option; the consequence is shown for the chosen one. */}
          <div className="space-y-2">
            <Label>{t("whatToPush")}</Label>
            <ChoiceField
              value={mode}
              onChange={setMode}
              disabled={pending}
              variant="card"
              options={PUSH_MODES.map((value) => ({
                value,
                label: t(`modes.${value}.label`),
                hint: t(`modes.${value}.summary`),
                icon: MODE_ICONS[value],
              }))}
            />
          </div>

          {mode ? (
            <p className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/5 p-3 text-sm">
              <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
              <span>{t(`modes.${mode}.consequence`)}</span>
            </p>
          ) : null}

          {replacesDatabase ? (
            <SafetyCopySwitch
              id="staging-push-safety-copy"
              checked={safetyCopy}
              onCheckedChange={setSafetyCopy}
              disabled={pending}
              label={t("safetyCopyLabel")}
              hint={t("safetyCopyHint")}
            />
          ) : null}
          {replacesDatabase && !safetyCopy ? (
            <Caution tone="destructive" size="md">
              <p>{t("noCopyWarning")}</p>
            </Caution>
          ) : null}

          {/* Without the copy only the files are put back on a failure. */}
          <p className="text-xs leading-5 text-muted-foreground">
            {t(replacesDatabase && !safetyCopy ? "whileRunningNoCopy" : "whileRunning")}
          </p>

          <TypeToConfirm
            id="staging-push-confirm"
            value={domain}
            copyLabel={t("copyDomain")}
            confirm={confirm}
            onChange={setConfirm}
            disabled={pending}
            matches={matches}
          />
        </div>

        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>{t("cancel")}</AlertDialogCancel>
          <ReasonTooltip reason={blocker}>
            <AlertDialogAction
              onClick={(event) => {
                // Stays open until the request returns; the push takes minutes.
                event.preventDefault();
                push();
              }}
              disabled={Boolean(blocker) || pending}
              variant="destructive"
            >
              {pending ? <Loader2 className="size-4 animate-spin" /> : null}
              {pending ? t(canBackUp && backup ? "pushingWithBackup" : "pushing") : t("submit")}
            </AlertDialogAction>
          </ReasonTooltip>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
