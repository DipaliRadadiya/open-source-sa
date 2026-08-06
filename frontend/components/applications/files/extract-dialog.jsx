"use client";

import { useTranslations } from "next-intl";
import { FolderOpen } from "lucide-react";
import { extractFile } from "@/lib/api/files";
import { dirname } from "@/lib/files/path-helpers";
import { TargetPathDialog } from "@/components/applications/files/target-path-dialog";

// Defaults to "right here" — the archive's own directory — which the API doc
// itself gives as the real-world case (unzip a plugin into wp-content/plugins).
// Extraction is in-place and can overwrite, so that's said up front, not
// discovered afterwards.
export function ExtractDialog({ appId, file, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  if (!file) return null;

  return (
    <TargetPathDialog
      appId={appId}
      file={file}
      open={open}
      onOpenChange={onOpenChange}
      icon={FolderOpen}
      title={t("extractDialog.title", { name: file.name })}
      description={t("extractDialog.subtitle")}
      submitLabel={t("extractDialog.submit")}
      savingLabel={t("saving")}
      defaultTarget={dirname(file.path)}
      allowEmpty
      emptyPlaceholder={t("targetDialog.rootPlaceholder")}
      apply={extractFile}
      successMessage={() => t("extractDialog.done", { name: file.name })}
      failureMessage={t("extractDialog.failed")}
      warning={t("extractDialog.warning")}
    />
  );
}
