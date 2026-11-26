import { restoreFileContent } from "@/lib/api/files";
import { RestoreVersionDialog } from "@/components/backups/restore-version-dialog";

export function RestoreFileBackupDialog({ appId, path, onRestored, ...props }) {
  return (
    <RestoreVersionDialog
      {...props}
      namespace="applications.files"
      safetyCopyId="file-restore-safety-copy"
      nameOnHover
      restore={({ backup, safetyCopy }) => restoreFileContent(appId, path, backup, { safetyCopy })}
      onRestored={() => onRestored?.()}
    />
  );
}
