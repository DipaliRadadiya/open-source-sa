import { restoreEnvironment } from "@/lib/api/environment";
import { RestoreVersionDialog } from "@/components/backups/restore-version-dialog";

export function RestoreBackupDialog({ appId, requiresRestart = false, onRestored, ...props }) {
  return (
    <RestoreVersionDialog
      {...props}
      namespace="applications.environment"
      safetyCopyId="env-restore-safety-copy"
      restartOption={requiresRestart}
      restore={(choice) => restoreEnvironment(appId, choice)}
      onRestored={(data) => onRestored?.(data?.environment ?? null)}
    />
  );
}
