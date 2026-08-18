"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowUpFromLine, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { PUSH_MODES } from "@/lib/schemas/application-staging";
import { pushApplicationStaging } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
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
          {/* Said before the choice, because it is true of both options and is
              the part people do not expect: the site is down while this runs. */}
          <p className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm">
            {t("downtime")}
          </p>

          {/* Repeated here, not just on the card behind the dialog. The age of
              the copy is the fact that decides whether this is routine or a
              mistake, and it has to be readable at the moment of committing. */}
          {staging?.created_at_human ? (
            <p className="text-sm text-muted-foreground">
              {t("copyAge", { age: staging.created_at_human })}
            </p>
          ) : null}

          <div className="space-y-1.5">
            <Label>{t("whatToPush")}</Label>
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
              }))}
            />
          </div>

          {/* Offered here rather than left as advice: a push cannot be undone,
              and the panel can take a backup in one click. */}
          <p className="text-sm text-muted-foreground">
            {t.rich("backupFirst", {
              link: (chunks) => (
                <Link
                  href={`/applications/${appId}/backups`}
                  className="font-medium text-foreground underline underline-offset-4"
                >
                  {chunks}
                </Link>
              ),
            })}
          </p>

          <div className="space-y-1.5">
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
