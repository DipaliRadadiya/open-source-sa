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
      defaultTarget={compressSuggestion(file.path, ".zip", new Set(existingPaths))}
      renderExtra={(field) => <ArchiveFormatField {...format} {...field} />}
      normalize={format.complete}
      validate={format.validate}
      apply={compressFile}
      successMessage={() => t("compressDialog.done", { name: file.name })}
      failureMessage={t("compressDialog.failed")}
      onSuccess={onSuccess}
      /*
       * The archive does not have to land beside what it contains.
       *
       * It never did — the backend resolves the whole relative path and writes
       * there — but the field reads as a filename box, so the folder half of
       * the suggestion looked like decoration and nobody tried changing it.
       * Naming the folder underneath, live, is what makes the capability
       * visible. `dirname` because the file NAME is already on screen in the
       * field; repeating it here would say nothing.
       */
      destinationLabel={t("compressDialog.savesTo")}
      destinationOf={dirname}
      warning={t("compressDialog.folderMustExist")}
    />
  );
}
