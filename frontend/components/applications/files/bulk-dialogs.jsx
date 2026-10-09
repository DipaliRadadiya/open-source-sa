import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Copy,
  FileArchive,
  FolderInput,
  Loader2,
  Lock,
  TriangleAlert,
} from "lucide-react";
import {
  compressFiles,
  copyFiles,
  deleteFiles,
  moveFiles,
  setFilesPermissions,
} from "@/lib/api/files";
import { bulkResult } from "@/lib/files/bulk-result";
import { apiMessage } from "@/lib/api/error-message";
import { compressSuggestion, dirname, inFolder, joinPath } from "@/lib/files/path-helpers";
import { sharedMode, selectedFiles } from "@/lib/files/shared-mode";
import { symbolicMode } from "@/lib/files/describe-mode";

import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PermissionModeField } from "@/components/applications/files/permission-mode-field";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { FormModal } from "@/components/ui/form-modal";
import {
  ArchiveFormatField,
  useArchiveFormat,
} from "@/components/applications/files/archive-format-field";
import { PermanentDeleteField } from "@/components/applications/files/permanent-delete-field";
import { useRefresh } from "@/hooks/use-refresh";

// Each dialog hands the per-path result to the panel; a toast cannot carry a list of paths.
export function BulkDialogs({ appId, action, paths: selectedPaths, files = [], path, onOpenChange, onResult }) {
  // Fixed at open: the list refreshes before the dialog closes, and after a move
  // the live selection is empty (`dirname(paths[0])` would crash).
  const [paths] = useState(selectedPaths);
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const { pending: refreshing, refreshThen } = useRefresh();
  const [running, setBusy] = useState(false);
  const busy = running || refreshing;
  // Move and copy start empty: the current folder is the one destination certain to fail.
  const [target, setTarget] = useState(() =>
    action === "compress" ? compressSuggestion(joinPath(path, "archive"), ".zip", new Set(files.map((f) => f.path))) : "",
  );
  // Seed from the actual mode: forcing 644 onto a folder strips its execute bit.
  const chosen = selectedFiles(files, paths);
  const currentMode = sharedMode(chosen);
  // A mixed selection starts with nothing chosen; a pre-filled mode could apply 644 to a
  // 600 secrets file without anyone choosing it.
  const mustChooseMode = action === "permissions" && !currentMode;
  // Only for the `d`/`-`/`l` prefix on the symbolic form. A mixed selection gets
  // the plain file prefix rather than calling a folder a file.
  const sharedType = chosen.every((file) => file.type === chosen[0]?.type)
    ? chosen[0]?.type
    : null;
  // Empty, never a hardcoded default, when there is no shared current value (see
  // `mustChooseMode`); the field shows no preset as chosen for "".
  const [mode, setMode] = useState(() => currentMode ?? "");
  const [error, setError] = useState(null);
  const archiveFormat = useArchiveFormat();
  // Defaults on each mount; BulkDialogs is mounted per action, so no value carries
  // between two deletes.
  const [permanent, setPermanent] = useState(true);

  async function run(call) {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      const { data } = await call();
      const result = bulkResult(data, paths);
      refreshThen(() => {
        onResult(action, result, { permanent });
        onOpenChange(false);
      });
    } catch (err) {
      // A 422 refuses the whole request (bad target, selection spanning folders, stale
      // count), so it is shown in the dialog next to the field, not in a toast.
      const field =
        err.response?.data?.errors?.target?.[0] ??
        err.response?.data?.errors?.target_directory?.[0] ??
        err.response?.data?.errors?.mode?.[0] ??
        err.response?.data?.errors?.paths?.[0];
      if (field) setError(field);
      else if ([404, 409, 422].includes(err.response?.status)) setError(apiMessage(err, t("bulk.failed")));
      else toast.error(apiMessage(err, t("bulk.failed")));
    } finally {
      setBusy(false);
    }
  }

  if (action === "delete") {
    return (
      <ConfirmDialog
        open
        onOpenChange={(next) => !busy && onOpenChange(next)}
        className="w-full sm:!max-w-lg"
        icon={TriangleAlert}
        tone="destructive"
        title={t("bulk.deleteTitle", { count: paths.length })}
        description={
          permanent ? t("bulk.deleteDescriptionPermanent") : t("bulk.deleteDescription")
        }
        cancelLabel={t("cancel")}
        confirmLabel={
          busy
            ? t("bulk.deleting")
            : permanent
              ? t("bulk.deleteSubmitForever")
              : t("bulk.deleteSubmit")
        }
        pending={busy}
        onConfirm={() => run(() => deleteFiles(appId, paths, { permanent }))}
      >
        {/* Named, not counted, so the user can check what is deleted. Bounded and
            scrolling so a long list cannot push the buttons off screen. */}
        <ul className="max-h-56 space-y-1 overflow-y-auto rounded-lg border p-2">
          {paths.map((entry) => (
            <li key={entry} className="font-mono text-xs break-all">
              {entry}
            </li>
          ))}
        </ul>
        <PermanentDeleteField
          id="bulk-delete-permanent"
          checked={permanent}
          onChange={setPermanent}
          disabled={busy}
        />
      </ConfirmDialog>
    );
  }

  const meta = {
    move: {
      icon: FolderInput,
      submit: () => moveFiles(appId, paths, target.trim()),
      label: t("bulk.targetFolder"),
      placeholder: t("bulk.targetFolderPlaceholder"),
      hint: t("bulk.moveHint"),
    },
    copy: {
      icon: Copy,
      submit: () => copyFiles(appId, paths, target.trim()),
      label: t("bulk.targetFolder"),
      placeholder: t("bulk.targetFolderPlaceholder"),
      hint: t("bulk.copyHint"),
    },
    compress: {
      icon: FileArchive,
      // A bare name lands beside the selection, as the hint says (not at the site's
      // top folder, which may be the public web root).
      submit: () => compressFiles(appId, paths, archiveFormat.complete(inFolder(target.trim(), dirname(paths[0])))),
      label: t("bulk.archiveName"),
      placeholder: t("bulk.archiveNamePlaceholder"),
      hint: t("bulk.compressHint", { folder: dirname(paths[0]) || "/" }),
    },
    permissions: { icon: Lock },
  }[action];

  if (!meta) return null;

  const isPermissions = action === "permissions";

  return (
    <FormModal
      open
      onOpenChange={(next) => !busy && onOpenChange(next)}
      asForm
      onSubmit={(event) => {
        event.preventDefault();
        if (action === "compress") {
          const invalid = archiveFormat.validate(archiveFormat.complete(inFolder(target.trim(), dirname(paths[0]))));
          if (invalid) {
            setError(invalid);
            return;
          }
        }
        run(isPermissions ? () => setFilesPermissions(appId, paths, mode) : meta.submit);
      }}
      icon={meta.icon}
      title={t(`bulk.${action}Title`, { count: paths.length })}
      // The current mode, or that the selection has no single value.
      description={
        isPermissions
          ? currentMode
            ? t("bulk.permissionsDescriptionCurrent", {
                mode: currentMode,
                symbolic: symbolicMode(currentMode, sharedType) ?? currentMode,
              })
            : t("bulk.permissionsDescriptionMixed")
          : t(`bulk.${action}Description`)
      }
      footer={
        <>
          <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={() => onOpenChange(false)}
          >
            {t("cancel")}
          </Button>
          {/* Disabled with a reason, never hidden; for a mixed selection the reason says a
              mode must be chosen. */}
          <ReasonTooltip
            reason={
              busy
                ? null
                : mustChooseMode && !mode
                  ? t("bulk.permissionsChooseMode")
                  : !isPermissions && !target.trim()
                    ? tc("enterAValue")
                    : null
            }
          >
          <Button
            type="submit"
            disabled={busy || (isPermissions ? !mode : !target.trim())}
          >
            {busy ? <Loader2 className="size-4 animate-spin" /> : null}
            {/* "Save", as in the single-file dialog; "Permissions" names the job, not the action. */}
            {isPermissions ? t("permissionsDialog.submit") : t(`bulk.${action}`)}
          </Button>
          </ReasonTooltip>
        </>
      }
    >
      {isPermissions ? (
        // Same control as the single-file Permissions dialog, so no octal is needed. No
        // label: the picker names its own rows and columns.
        <PermissionModeField mode={mode} onChange={setMode} invalid={Boolean(error)} />
      ) : (
        <div className="space-y-2">
          {action === "compress" ? (
            <div className="pb-2">
              <ArchiveFormatField
                {...archiveFormat}
                value={target}
                setValue={setTarget}
                busy={busy}
                suggest={(ext) => compressSuggestion(joinPath(path, "archive"), ext, new Set(files.map((f) => f.path)))}
              />
            </div>
          ) : null}
          <Label htmlFor="bulk-target">{meta.label}</Label>
          <Input
            id="bulk-target"
            value={target}
            placeholder={meta.placeholder}
            onChange={(event) => setTarget(event.target.value)}
            autoComplete="off"
            spellCheck={false}
            className="font-mono text-xs"
            aria-invalid={Boolean(error)}
          />
          <p className="text-xs text-muted-foreground">{meta.hint}</p>
        </div>
      )}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </FormModal>
  );
}
