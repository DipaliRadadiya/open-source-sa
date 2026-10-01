import { useTranslations } from "next-intl";
import { Archive } from "lucide-react";
import { compressFile } from "@/lib/api/files";
import { compressSuggestion, dirname } from "@/lib/files/path-helpers";
import { TargetPathDialog } from "@/components/applications/files/target-path-dialog";
import { ArchiveFormatField, useArchiveFormat } from "@/components/applications/files/archive-format-field";

export function CompressDialog({ appId, file, existingPaths, open, onOpenChange, onSuccess }) {
  const t = useTranslations("applications.files");
  const format = useArchiveFormat();
  if (!file) return null;
  const suggest = (ext) => compressSuggestion(file.path, ext, new Set(existingPaths));

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
      defaultTarget={suggest(".zip")}
      renderExtra={(field) => <ArchiveFormatField {...format} {...field} suggest={suggest} />}
      normalize={format.complete}
      validate={format.validate}
      apply={compressFile}
      successMessage={() => t("compressDialog.done", { name: file.name })}
      failureMessage={t("compressDialog.failed")}
      onSuccess={onSuccess}
      /*
       * The backend resolves the whole relative path, so the archive can go anywhere.
       * Naming the destination folder live makes that visible; `dirname` because the
       * file name is already in the field.
       */
      destinationLabel={t("compressDialog.savesTo")}
      destinationOf={dirname}
      warning={t("compressDialog.folderMustExist")}
    />
  );
}
