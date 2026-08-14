"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
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
import { dirname, joinPath } from "@/lib/files/path-helpers";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PermissionModeField } from "@/components/applications/files/permission-mode-field";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { FormModal } from "@/components/ui/form-modal";
import {
  ArchiveFormatField,
  useArchiveFormat,
} from "@/components/applications/files/archive-format-field";

/**
 * The dialogs behind the selection bar. One component because all four share
 * the same ending: run the call, hand the per-path result back to the panel,
 * and let the panel decide what to show. None of them reports success itself
 * when something failed — a toast cannot carry a list of paths.
 */
export function BulkDialogs({ appId, action, paths, path, onOpenChange, onResult }) {
  const t = useTranslations("applications.files");
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [target, setTarget] = useState(() =>
    action === "compress" ? joinPath(path, "archive.zip") : path,
  );
  const [mode, setMode] = useState("644");
  const [error, setError] = useState(null);
  const archiveFormat = useArchiveFormat();

  async function run(call) {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      const { data } = await call();
      const result = bulkResult(data, paths);
      onResult(action, result);
      onOpenChange(false);
      router.refresh();
    } catch (err) {
      // A 422 here is the whole request refused — a bad target, a selection
      // spanning folders, a count that no longer matches. It belongs in the
      // dialog next to the field that caused it, not in a toast.
      const field =
        err.response?.data?.errors?.target?.[0] ??
        err.response?.data?.errors?.target_directory?.[0] ??
        err.response?.data?.errors?.mode?.[0] ??
        err.response?.data?.errors?.paths?.[0];
      if (field) setError(field);
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
        description={t("bulk.deleteDescription")}
        cancelLabel={t("cancel")}
        confirmLabel={busy ? t("bulk.deleting") : t("bulk.deleteSubmit")}
        pending={busy}
        onConfirm={() => run(() => deleteFiles(appId, paths))}
      >
        {/* Named, not counted. "Delete 12 items?" is not something anyone can
            check; a list is. Bounded and scrolling so 250 of them cannot push
            the buttons off the screen. */}
        <ul className="max-h-56 space-y-1 overflow-y-auto rounded-lg border p-2">
          {paths.map((entry) => (
            <li key={entry} className="font-mono text-xs break-all">
              {entry}
            </li>
          ))}
        </ul>
      </ConfirmDialog>
    );
  }

  const meta = {
    move: {
      icon: FolderInput,
      submit: () => moveFiles(appId, paths, target.trim()),
      label: t("bulk.targetFolder"),
      hint: t("bulk.moveHint"),
    },
    copy: {
      icon: Copy,
      submit: () => copyFiles(appId, paths, target.trim()),
      label: t("bulk.targetFolder"),
      hint: t("bulk.copyHint"),
    },
    compress: {
      icon: FileArchive,
      submit: () => compressFiles(appId, paths, target.trim()),
      label: t("bulk.archiveName"),
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
          const invalid = archiveFormat.validate(target.trim());
          if (invalid) {
            setError(invalid);
            return;
          }
        }
        run(isPermissions ? () => setFilesPermissions(appId, paths, mode) : meta.submit);
      }}
      icon={meta.icon}
      title={t(`bulk.${action}Title`, { count: paths.length })}
      description={t(`bulk.${action}Description`)}
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
          <Button type="submit" disabled={busy || (!isPermissions && !target.trim())}>
            {busy ? <Loader2 className="size-4 animate-spin" /> : null}
            {/* "Permissions" is the name of the job, not something you can do —
                the single-file dialog says Save here, and so does this one. */}
            {isPermissions ? t("permissionsDialog.submit") : t(`bulk.${action}`)}
          </Button>
        </>
      }
    >
      {isPermissions ? (
        // The same control as a single file's Permissions dialog. This was a row
        // of raw octal buttons and a number box — asking people to know the
        // 4/2/1 mask for exactly the job the single-file dialog had already
        // decided they should not have to. No label above it: the picker names
        // its own rows and columns, and the dialog is titled "Permissions".
        <PermissionModeField mode={mode} onChange={setMode} invalid={Boolean(error)} />
      ) : (
        <div className="space-y-2">
          {action === "compress" ? (
            <div className="pb-2">
              <ArchiveFormatField {...archiveFormat} value={target} setValue={setTarget} busy={busy} />
            </div>
          ) : null}
          <Label htmlFor="bulk-target">{meta.label}</Label>
          <Input
            id="bulk-target"
            value={target}
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
