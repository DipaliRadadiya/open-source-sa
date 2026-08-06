"use client";

import { useTranslations } from "next-intl";
import { Copy } from "lucide-react";
import { copyFile } from "@/lib/api/files";
import { copySuggestion } from "@/lib/files/path-helpers";
import { TargetPathDialog } from "@/components/applications/files/target-path-dialog";

export function CopyDialog({ appId, file, open, onOpenChange, onSuccess }) {
  const t = useTranslations("applications.files");
  if (!file) return null;

  return (
    <TargetPathDialog
      appId={appId}
      file={file}
      open={open}
      onOpenChange={onOpenChange}
      icon={Copy}
      title={t("copyDialog.title", { name: file.name })}
      description={t("copyDialog.subtitle")}
      submitLabel={t("copyDialog.submit")}
      savingLabel={t("saving")}
      defaultTarget={copySuggestion(file.path)}
      apply={copyFile}
      successMessage={() => t("copyDialog.done", { name: file.name })}
      failureMessage={t("copyDialog.failed")}
      onSuccess={onSuccess}
    />
  );
}
