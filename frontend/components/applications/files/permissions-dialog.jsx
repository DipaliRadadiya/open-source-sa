import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Lock } from "lucide-react";
import { setFilePermissions } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { PermissionModeField } from "@/components/applications/files/permission-mode-field";
import { modeParts } from "@/lib/files/describe-mode";

const DEFAULT_MODE = "644";

// Mounted fresh per file (see files-panel.jsx), so the pre-selected choice
// is the initial state directly rather than something an effect resets.
// When the listing sent a current `mode`, it's used as the starting point
// instead of always defaulting to 644 regardless of what the file actually
// has — older backends that don't send `mode` yet still get today's behavior.
export function PermissionsDialog({ appId, file, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  const router = useRouter();
  const currentMode = file?.mode ?? null;
  // One piece of state: the mode itself. The checkboxes edit its digits, so no
  // combination can be entered that the server would reject.
  const [mode, setMode] = useState(() =>
    modeParts(currentMode) ? currentMode : DEFAULT_MODE,
  );
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  function handleOpenChange(next) {
    if (busy) return;
    onOpenChange?.(next);
  }

  async function onSubmit(e) {
    e.preventDefault();
    // Accepts a four-digit mode too. This used to refuse one outright — and
    // refuse it SILENTLY, with a bare return: on a sticky directory, pressing
    // Save did nothing at all and said nothing about why.
    if (busy) return;
    if (!modeParts(mode)) {
      setError(t("permissionsDialog.invalidMode"));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await setFilePermissions(appId, file.path, mode);
      toast.success(t("permissionsDialog.done", { name: file.name }));
      handleOpenChange(false);
      router.refresh();
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
      description={
        currentMode
          ? t("permissionsDialog.subtitleWithCurrent", { mode: currentMode })
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
