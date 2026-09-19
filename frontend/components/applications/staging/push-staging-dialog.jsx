import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ArrowUpFromLine,
  Clock,
  Database,
  FileText,
  Layers,
  Loader2,
  PowerOff,
  Undo2,
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
 * The most destructive action in the panel, and the only one with no undo for
 * files: the backend rsyncs with `--delete`, and `files` mode takes no safety
 * copy of anything. So this borrows the restore dialog's shape — icon header,
 * the facts as facts, and a typed domain before the button unlocks — because
 * the two actions carry the same weight and should not feel different.
 *
 * The mode has no preselected value on purpose. `PushStagingRequest` calls
 * `files` "the only mode that cannot lose data" and asks the form to default
 * to it; that is not true, and defaulting to it would turn a claim the code
 * makes about itself into the click most people never think about. Each option
 * says what it destroys and the reader picks one.
 *
 * Callers MUST pass a `key` that changes when this opens. A dialog opened
 * from its own button never fires `onOpenChange` on the way in, so a mode
 * chosen and a domain typed for one visit would still be sitting there the
 * next time — pre-arming the safeguard that exists to slow the click down.
 */
export function PushStagingDialog({ appId, production, staging, open, onOpenChange }) {
  const t = useTranslations("applications.staging.pushDialog");
  const router = useRouter();
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
      onOpenChange(false);
      toast.success(t("done", { domain }));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
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
            <AlertDialogTitle>{t("title", { domain })}</AlertDialogTitle>
          </div>
          <AlertDialogDescription className="pt-1">
            {t("description", { staging: staging?.domain ?? "", production: domain })}
          </AlertDialogDescription>
        </AlertDialogHeader>

        <div className="space-y-4">
          {/*
           * The two costs, together, in one block and above the choice —
           * they are true of every mode, and they are the parts people do not
           * expect. They were a tinted paragraph and a line of grey body text
           * either side of the options: the single most important sentence in
           * the dialog, "there is no way back from this", was the smallest
           * thing on screen and sat BELOW the decision it should inform.
           *
           * One block with two rows rather than two banners. Two full-width
           * warnings shout equally and the second stops being read.
           */}
          <div className="space-y-2.5 rounded-lg border border-destructive/30 bg-destructive/5 p-3.5 text-sm">
            <p className="flex items-start gap-2.5">
              <PowerOff className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
              <span>{t("downtime")}</span>
            </p>
            <p className="flex items-start gap-2.5">
              <Undo2 className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
              <span>
                {t.rich("backupFirst", {
                  link: (chunks) => (
                    <Link
                      href={`/applications/${appId}/backups`}
                      className="font-medium underline underline-offset-4"
                    >
                      {chunks}
                    </Link>
                  ),
                })}
              </span>
            </p>
          </div>

          <div className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <Label hint={t("whatToPushHint")}>{t("whatToPush")}</Label>
              {/*
               * The age of the copy decides whether this push is routine or a
               * mistake, so it belongs beside the choice it informs. As a bare
               * grey line floating between a red panel and a heading it
               * belonged to neither of them.
               */}
              {staging?.created_at_human ? (
                <span className="inline-flex items-center gap-1.5 rounded-full border bg-muted/50 px-2.5 py-1 text-xs text-muted-foreground">
                  <Clock className="size-3.5 shrink-0" aria-hidden />
                  {t("copyAge", { age: staging.created_at_human })}
                </span>
              ) : null}
            </div>
            <ChoiceField
              value={mode}
              onChange={setMode}
              disabled={pending}
              variant="card"
              // `hint`, not `description` — ChoiceField renders the former and
              // silently drops anything else, which took the sentence naming
              // what each mode destroys off the screen entirely.
              options={PUSH_MODES.map((value) => ({
                value,
                label: t(`modes.${value}.label`),
                hint: t(`modes.${value}.description`),
                // Files, a database, or both: three different kinds of thing,
                // which is the case an icon actually helps with.
                icon: MODE_ICONS[value],
              }))}
            />
          </div>

          {/*
           * The gate, in a surface of its own. As a loose label and input at
           * the bottom of a long dialog it read as one more field; it is the
           * last thing between a click and an irreversible action.
           */}
          <div className="space-y-1.5 rounded-lg border bg-muted/30 p-3.5">
            {/* The most dangerous action in the panel, and the domain is the
                only thing standing in front of it — no reason to make it a
                transcription test as well as a decision. */}
            <div className="flex items-start justify-between gap-2">
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
              className={cn(
                "bg-background font-mono",
                matches && "border-success focus-visible:border-success",
              )}
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
