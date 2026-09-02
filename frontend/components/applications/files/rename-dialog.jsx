import { useTranslations } from "next-intl";
import { PencilLine } from "lucide-react";
import { renameFile } from "@/lib/api/files";
import { dirname } from "@/lib/files/path-helpers";
import { TargetPathDialog } from "@/components/applications/files/target-path-dialog";

// Pre-filled with the item's own current path, with just the filename part
// selected — editing the selected text renames in place; editing the
// directory prefix moves it. One field, one endpoint, reads as "rename" for
// the common case.
export function RenameDialog({ appId, file, open, onOpenChange, onSuccess }) {
  const t = useTranslations("applications.files");
  if (!file) return null;

  const dir = dirname(file.path);
  const selectFrom = dir ? dir.length + 1 : 0;

  return (
    <TargetPathDialog
      appId={appId}
      file={file}
      open={open}
      onOpenChange={onOpenChange}
      icon={PencilLine}
      title={t("rename.title", { name: file.name })}
      description={t("rename.subtitle")}
      submitLabel={t("rename.submit")}
      savingLabel={t("saving")}
      defaultTarget={file.path}
      selectFrom={selectFrom}
      selectTo={file.path.length}
      apply={renameFile}
      successMessage={() => t("rename.done", { name: file.name })}
      failureMessage={t("rename.failed")}
      onSuccess={onSuccess}
    />
  );
}
