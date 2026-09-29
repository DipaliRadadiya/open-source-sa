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
import { cn } from "@/lib/utils";
import { PUSH_MODES } from "@/lib/schemas/application-staging";

const MODE_ICONS = { files: FileText, database: Database, full: Layers };
import { pushApplicationStaging } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { ChoiceField } from "@/components/ui/choice-field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CopyButton } from "@/components/ui/copy-button";
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
 * Copy staging over production.
 *
 * The most destructive action in the panel. The backend rsyncs with
 * `--delete` (uploads excepted — they are merged, never removed), and the
 * snapshot it takes first is only for putting production back if the push
 * FAILS; once it succeeds there is no way back from the panel. So this borrows
 * the restore dialog's shape — icon header, the facts as facts, and a typed
 * domain before the button unlocks — because the two actions carry the same
 * weight and should not feel different.
 *
 * The mode has no preselected value on purpose. `PushStagingRequest` calls
 * `files` "the only mode that cannot lose data" and asks the form to default
 * to it; it deletes production-only files, and defaulting to it would turn a
 * claim the code makes about itself into the click most people never think
 * about. Each option says what it destroys and the reader picks one.
 *
 * Callers MUST pass a `key` that changes when this opens. A dialog opened
 * from its own button never fires `onOpenChange` on the way in, so a mode
 * chosen and a domain typed for one visit would still be sitting there the
 * next time — pre-arming the safeguard that exists to slow the click down.
 */
export function PushStagingDialog({ appId, production, staging, open, onOpenChange }) {
  const t = useTranslations("applications.staging.pushDialog");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [mode, setMode] = useState("");
  const [confirm, setConfirm] = useState("");
  const [pending, setPending] = useState(false);

  const domain = production?.domain ?? "";
  const matches = confirm.trim() === domain && domain !== "";
  const blocker = !mode ? t("pickMode") : !matches ? t("typeToUnlock") : null;

  async function push() {
    setPending(true);
    try {
      await pushApplicationStaging(appId, mode);
      await refreshAndWait();
      onOpenChange(false);
      toast.success(t("done", { domain }));
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
      // The usual cause is the copy having gone (deleted in another tab), and
      // the page behind this dialog still showed it. Re-read, so it says so.
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
          {/* Which copy goes where, in one line. */}
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
          {/* Read before choosing: no option can be undone (and the new tab
              keeps this push waiting — Krishna, 2026-09-29). */}
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

          {/* One line per option (research-staging-push-ui: every panel
              surveyed says what each choice is in a phrase; none shows a
              comparison table). The cost is said once, for the one chosen. */}
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

          <p className="text-xs leading-5 text-muted-foreground">{t("whileRunning")}</p>

          {/* The most dangerous action in the panel, and the domain is the
              only thing standing in front of it — no reason to make it a
              transcription test as well as a decision. */}
          <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-2">
              <Label htmlFor="staging-push-confirm">{t("confirmLabel", { domain })}</Label>
              <CopyButton value={domain} label={t("copyDomain")} className="size-6 shrink-0" />
            </div>
            <Input
              id="staging-push-confirm"
              value={confirm}
              onChange={(event) => setConfirm(event.target.value)}
              disabled={pending}
              autoComplete="off"
              spellCheck={false}
              placeholder={domain}
              className={cn("font-mono", matches && "border-success focus-visible:border-success")}
            />
          </div>
        </div>

        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>{t("cancel")}</AlertDialogCancel>
          <ReasonTooltip reason={blocker}>
            <AlertDialogAction
              onClick={(event) => {
                // Closes when the request comes back, not on click — the push
                // blocks for minutes and a dialog that vanishes first leaves
                // no sign anything is happening.
                event.preventDefault();
                push();
              }}
              disabled={Boolean(blocker) || pending}
              variant="destructive"
            >
              {pending ? <Loader2 className="size-4 animate-spin" /> : null}
              {pending ? t("pushing") : t("submit")}
            </AlertDialogAction>
          </ReasonTooltip>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
