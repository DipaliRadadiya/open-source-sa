import { useState } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ArrowDownToLine,
  ArrowRight,
  Database,
  FileText,
  Layers,
  Loader2,
  ShieldCheck,
  TriangleAlert,
} from "lucide-react";
// A pull takes the same three modes as a push, pointed the other way.
import { PUSH_MODES } from "@/lib/schemas/application-staging";
import { pullApplicationStaging } from "@/lib/api/applications";
import { apiMessage, mayStillBeRunning } from "@/lib/api/error-message";
import { ChoiceField } from "@/components/ui/choice-field";
import { Label } from "@/components/ui/label";
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

const MODE_ICONS = { files: FileText, database: Database, full: Layers };

// No typed domain, unlike a push: live is only read, and staging's database is
// saved first. Staging's own file changes are not kept, so no mode is preselected.
// Callers MUST pass a `key` that changes on open.
export function PullStagingDialog({ appId, production, staging, open, onOpenChange }) {
  const t = useTranslations("applications.staging.pullDialog");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [mode, setMode] = useState(null);
  const [pending, setPending] = useState(false);

  async function pull() {
    setPending(true);
    try {
      await pullApplicationStaging(appId, mode);
      await refreshAndWait();
      onOpenChange(false);
      toast.success(t("done", { domain: staging?.domain ?? "" }));
    } catch (error) {
      // A retry would only be refused as "already running"; close and let the page catch up.
      if (mayStillBeRunning(error)) {
        onOpenChange(false);
        toast.warning(t("stillRunning", { domain: staging?.domain ?? "" }), { duration: 20000 });
        router.refresh();
        return;
      }
      toast.error(apiMessage(error, t("failed")));
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
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warning/10 text-warning">
              <ArrowDownToLine className="size-5" />
            </span>
            <AlertDialogTitle>{t("title")}</AlertDialogTitle>
          </div>
          <AlertDialogDescription className="pt-1">
            <span className="font-mono break-words">{production?.domain}</span>
            <ArrowRight className="mx-1.5 inline size-3.5 align-[-2px]" aria-hidden />
            <span className="font-mono font-medium break-words text-foreground">{staging?.domain}</span>
          </AlertDialogDescription>
        </AlertDialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label>{t("whatToCopy")}</Label>
            <ChoiceField
              value={mode ?? ""}
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
            <>
              <p className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/5 p-3 text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
                <span>{t(`modes.${mode}.consequence`)}</span>
              </p>
              {/* Only the database is kept after a successful update; the file snapshot is not. */}
              <p className="flex items-start gap-2.5 rounded-lg border border-success/40 bg-success/10 p-3 text-sm">
                <ShieldCheck className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
                <span>{t(mode === "files" ? "safetyFiles" : "safetyDatabase")}</span>
              </p>
            </>
          ) : null}

          <p className="text-xs leading-5 text-muted-foreground">{t("keeps")}</p>
        </div>

        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>{t("cancel")}</AlertDialogCancel>
          <ReasonTooltip reason={mode ? null : t("pickMode")}>
            <AlertDialogAction
              onClick={(event) => {
                // Stays open until the request returns; copying a site takes minutes.
                event.preventDefault();
                pull();
              }}
              disabled={!mode || pending}
            >
              {pending ? <Loader2 className="size-4 animate-spin" /> : null}
              {pending ? t("pulling") : t("submit")}
            </AlertDialogAction>
          </ReasonTooltip>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
