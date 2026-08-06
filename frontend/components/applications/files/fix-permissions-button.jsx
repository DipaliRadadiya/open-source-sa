"use client";

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

/**
 * The whole-site reset — a different, page-level action from any one file's
 * own Permissions (⋯ menu), always targeting the application's own document
 * root, never a path the user picked.
 */
export function FixPermissionsButton({ appId, canManage }) {
  const t = useTranslations("applications.files");
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
      >
        {/* What changes, as scannable facts — not buried in the same
            sentence as when to use it. */}
        <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 rounded-lg border bg-muted/30 px-3 py-2.5 text-xs">
          <span className="text-muted-foreground">{t("fixPermissions.foldersLabel")}</span>
          <span className="text-right font-mono font-medium">755</span>
          <span className="text-muted-foreground">{t("fixPermissions.filesLabel")}</span>
          <span className="text-right font-mono font-medium">644</span>
          <span className="text-muted-foreground">{t("fixPermissions.protectedLabel")}</span>
          <span className="text-right font-medium">{t("fixPermissions.protectedValue")}</span>
        </div>
        <p className="mt-2 text-xs text-muted-foreground">{t("fixPermissions.whenToUse")}</p>
      </ConfirmDialog>
    </>
  );
}
