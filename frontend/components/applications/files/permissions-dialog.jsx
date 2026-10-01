import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Lock } from "lucide-react";
import { setFilePermissions } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { PermissionModeField } from "@/components/applications/files/permission-mode-field";
import { modeParts, symbolicMode } from "@/lib/files/describe-mode";
import { useRefresh } from "@/hooks/use-refresh";

const DEFAULT_MODE = "644";

// Mounted fresh per file (see files-panel.jsx), so the preselected mode is the
// initial state. Starts from the listing's `mode` when sent; backends that don't
// send it fall back to 644.
export function PermissionsDialog({ appId, file, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  const { pending: refreshing, refreshThen } = useRefresh();
  const currentMode = file?.mode ?? null;
  // One piece of state, the mode; the checkboxes edit its digits, so no combination
  // the server would reject can be entered.
  const [mode, setMode] = useState(() =>
    modeParts(currentMode) ? currentMode : DEFAULT_MODE,
  );
  const [error, setError] = useState(null);
  const [saving, setBusy] = useState(false);
  const busy = saving || refreshing;

  function handleOpenChange(next) {
    if (busy) return;
    onOpenChange?.(next);
  }

  async function onSubmit(e) {
    e.preventDefault();
    // Four-digit modes (e.g. sticky directories) are accepted too.
    if (busy) return;
    if (!modeParts(mode)) {
      setError(t("permissionsDialog.invalidMode"));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await setFilePermissions(appId, file.path, mode);
      refreshThen(() => {
        toast.success(t("permissionsDialog.done", { name: file.name }));
        onOpenChange?.(false);
      });
    } catch (err) {
      const modeError = err.response?.data?.errors?.mode?.[0];
      if (modeError) setError(modeError);
      else toast.error(apiMessage(err, t("permissionsDialog.failed")));
    } finally {
      setBusy(false);
    }
  }

  if (!file) return null;

  return (
    <FormModal
      open={open}
      onOpenChange={handleOpenChange}
      asForm
      onSubmit={onSubmit}
      icon={Lock}
      title={t("permissionsDialog.title", { name: file.name })}
      /*
       * Names the mode both ways (octal and symbolic), matching the listing's
       * Permissions column.
       */
      description={
        currentMode
          ? t(
              symbolicMode(currentMode, file.type)
                ? "permissionsDialog.subtitleWithCurrentBoth"
                : "permissionsDialog.subtitleWithCurrent",
              { mode: currentMode, symbolic: symbolicMode(currentMode, file.type) ?? "" },
            )
          : t("permissionsDialog.subtitle")
      }
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={busy}>
            {t("cancel")}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? <Loader2 className="size-4 animate-spin" /> : null}
            {t("permissionsDialog.submit")}
          </Button>
        </>
      }
    >
      <PermissionModeField mode={mode} onChange={setMode} invalid={Boolean(error)} />
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </FormModal>
  );
}
