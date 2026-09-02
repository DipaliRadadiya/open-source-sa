import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Wrench } from "lucide-react";
import { fixApplicationPermissions } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { useModeSentence } from "@/components/applications/files/use-mode-sentence";

/**
 * The whole-site reset — a different, page-level action from any one file's
 * own Permissions (⋯ menu), always targeting the application's own document
 * root, never a path the user picked.
 */
export function FixPermissionsButton({ appId, canManage }) {
  const t = useTranslations("applications.files");
  const sentenceFor = useModeSentence();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      await fixApplicationPermissions(appId);
      toast.success(t("fixPermissions.done"));
      setOpen(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("fixPermissions.failed")));
    } finally {
      setPending(false);
    }
  }

  const canFix = canManage;

  return (
    <>
      <ReasonTooltip reason={canFix ? null : t("noPermission")}>
        <Button variant="outline" size="sm" disabled={!canFix} onClick={() => setOpen(true)}>
          <Wrench className="size-3.5" />
          {t("fixPermissions.action")}
        </Button>
      </ReasonTooltip>

      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        icon={Wrench}
        tone="warning"
        title={t("fixPermissions.title")}
        description={t("fixPermissions.description")}
        cancelLabel={t("cancel")}
        confirmLabel={t("fixPermissions.confirm")}
        pending={pending}
        onConfirm={onConfirm}
        className="w-full sm:!max-w-lg"
      >
        {/* What changes, as scannable facts — not buried in the same
            sentence as when to use it.

            Each mode is spelled out in the same words the permission picker
            uses, from the same helper. This dialog used to show `755` and `644`
            and nothing else: the one screen that changes every file on the site
            was the only one that expected you to read octal, while the dialog
            for a single file explained itself in full. */}
        <div className="divide-y rounded-lg border">
          {[
            { label: t("fixPermissions.foldersLabel"), mode: "755" },
            { label: t("fixPermissions.filesLabel"), mode: "644" },
          ].map(({ label, mode }) => (
            <div key={mode} className="space-y-1 px-4 py-3">
              <div className="flex items-center justify-between gap-3">
                {/* The label at reading size, not caption size — this is the
                    subject of the row, and the whole block used to be text-xs
                    grey, which is a footnote pretending to be the content. */}
                <span className="text-sm font-medium">{label}</span>
                {/* The number as a chip: it is a value, not a heading, and
                    right-aligned against the far edge it read as a column of
                    unrelated digits. */}
                <span className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs tabular-nums text-muted-foreground">
                  {mode}
                </span>
              </div>
              {sentenceFor(mode) ? (
                <p className="text-sm leading-relaxed text-muted-foreground">{sentenceFor(mode)}</p>
              ) : null}
            </div>
          ))}

          <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-3">
            <span className="text-sm font-medium">{t("fixPermissions.protectedLabel")}</span>
            <span className="font-mono text-xs text-muted-foreground">
              {t("fixPermissions.protectedValue")}
            </span>
          </div>
        </div>
        <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
          {t("fixPermissions.whenToUse")}
        </p>
      </ConfirmDialog>
    </>
  );
}
