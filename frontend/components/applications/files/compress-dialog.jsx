import { useTranslations } from "next-intl";
import { Archive } from "lucide-react";
import { compressFile } from "@/lib/api/files";
import { compressSuggestion } from "@/lib/files/path-helpers";
import { TargetPathDialog } from "@/components/applications/files/target-path-dialog";
import { ArchiveFormatField, useArchiveFormat } from "@/components/applications/files/archive-format-field";

export function CompressDialog({ appId, file, open, onOpenChange, onSuccess }) {
  const t = useTranslations("applications.files");
  const format = useArchiveFormat();
  if (!file) return null;

  return (
    <TargetPathDialog
      appId={appId}
      file={file}
      open={open}
      onOpenChange={onOpenChange}
      icon={Archive}
      title={t("compressDialog.title", { name: file.name })}
      description={t("compressDialog.subtitle")}
      submitLabel={t("compressDialog.submit")}
      savingLabel={t("saving")}
      defaultTarget={compressSuggestion(file.path)}
      renderExtra={(field) => <ArchiveFormatField {...format} {...field} />}
      validate={format.validate}
      apply={compressFile}
      successMessage={() => t("compressDialog.done", { name: file.name })}
      failureMessage={t("compressDialog.failed")}
      onSuccess={onSuccess}
    />
  );
}
