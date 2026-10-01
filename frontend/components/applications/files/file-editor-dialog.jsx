import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FileCode2, Loader2, History, Download, TriangleAlert } from "lucide-react";
import { getFileContent, saveFileContent, fileDownloadUrl } from "@/lib/api/files";
import { fileContentSchema } from "@/lib/schemas/file";
import { apiMessage } from "@/lib/api/error-message";
import { EDITOR_MAX_BYTES } from "@/lib/files/openable";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ShortcutHint } from "@/components/ui/shortcut-hint";
import { CopyButton } from "@/components/ui/copy-button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { RestoreFileBackupDialog } from "@/components/applications/files/restore-file-backup-dialog";

// CodeMirror and its language packages are only needed here, so they load on
// first open.
const CodeEditor = dynamic(
  () => import("@/components/applications/files/code-editor").then((m) => m.CodeEditor),
  {
    ssr: false,
    loading: () => (
      <div className="flex h-full items-center justify-center">
        <Loader2 className="size-5 animate-spin text-console-muted" />
      </div>
    ),
  },
);

/**
 * View/edit one text file, on the same console surface as the .env and php.ini
 * editors. Content is fetched on every open; the list carries only metadata.
 */
export function FileEditorDialog({ appId, file, canManage, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  // The row that opened the editor, captured before focus moves into it.
  const [opener] = useState(() => (typeof document === "undefined" ? null : document.activeElement));
  const saved = useRef(false);
  // Mounted fresh per file (see files-panel.jsx), so state starts at "about to
  // load" rather than being reset by an effect.
  const tooLarge = file.size > EDITOR_MAX_BYTES;
  const [loading, setLoading] = useState(!tooLarge);
  const [saving, setSaving] = useState(false);
  const [loaded, setLoaded] = useState(null); // { path, content, backups }
  const [contents, setContents] = useState("");
  // Set when the file can't be opened here at all (too large or binary); Download
  // is the way out.
  const [blocked, setBlocked] = useState(() => (tooLarge ? t("editor.tooLarge") : null));
  // Why the last save was refused, shown beside the unsaved text rather than in a
  // toast that clears itself.
  const [saveError, setSaveError] = useState(null);
  const [restoreOpen, setRestoreOpen] = useState(false);
  const [discardOpen, setDiscardOpen] = useState(false);

  /*
   * After a restore, re-read the file: the endpoint answers only `{restored: true}`,
   * and keeping the old text would let the next Save overwrite the restore. The
   * backup list also changes (the restore makes one).
   *
   * If the re-read fails the editor closes, since its text is known to be stale.
   */
  async function reloadAfterRestore() {
    setLoading(true);
    setSaveError(null);
    try {
      const { data } = await getFileContent(appId, file.path);
      const parsed = fileContentSchema.safeParse(data);
      if (!parsed.success) throw new Error("unreadable");
      setLoaded(parsed.data);
      setContents(parsed.data.content);
    } catch (error) {
      // Our sentence leads, the server's reason follows.
      toast.error(t("restore.reloadFailed"), { description: apiMessage(error, null) ?? undefined });
      onOpenChange?.(false);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    let active = true;
    if (tooLarge) return;
    getFileContent(appId, file.path)
      .then(({ data }) => {
        if (!active) return;
        const parsed = fileContentSchema.safeParse(data);
        if (!parsed.success) throw new Error("unreadable");
        setLoaded(parsed.data);
        setContents(parsed.data.content);
      })
      .catch((error) => {
        if (!active) return;
        // Both refusals are a bare 422 with a message and no field. A 422 that names a
        // field is a bad path, which is a real failure.
        const response = error.response;
        if (response?.status === 422 && !response.data?.errors?.path) {
          // A shortcut opens a file with no listing size; the server's sentence says which
          // refusal it was.
          setBlocked(
            file.size == null
              ? apiMessage(error, t("editor.notText"), { reference: false })
              : t(file.size > EDITOR_MAX_BYTES ? "editor.tooLarge" : "editor.notText"),
          );
        } else {
          toast.error(apiMessage(error, t("editor.loadFailed")));
          onOpenChange?.(false);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
    // `appId` is listed because the effect reads it; it never changes while
    // `file.path` holds today, but if it did, one site's file would load into
    // another's editor.
    //
    // `t`, `onOpenChange` and the setters stay out: they are only read on failure,
    // and re-running on a new translator identity would abort the in-flight request.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [appId, file.path, tooLarge]);

  const dirty = loaded !== null && contents !== loaded.content;
  const canEdit = canManage;

  async function save() {
    if (!dirty || saving) return;
    setSaving(true);
    setSaveError(null);
    try {
      await saveFileContent(appId, file.path, contents);
      await refreshAndWait();
      toast.success(t("editor.saved"));
      saved.current = true;
      onOpenChange?.(false);
    } catch (error) {
      // Shown in the dialog, not as a toast: the unsaved work stays open, so the reason
      // must too.
      setSaveError(apiMessage(error, t("editor.saveFailed")));
    } finally {
      setSaving(false);
    }
  }

  // Radix calls onOpenChange(false) for Escape, the backdrop and Cancel, so one
  // guard covers every way to close.
  function handleOpenChange(next) {
    if (saving) return;
    if (!next && dirty) {
      setDiscardOpen(true);
      return;
    }
    onOpenChange?.(next);
  }

  function confirmDiscard() {
    setDiscardOpen(false);
    onOpenChange?.(false);
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      {/* Sized to the viewport; `minmax(0,1fr)` lets the middle row shrink so the editor
          scrolls internally instead of pushing the footer off screen.
          
          Cmd/Ctrl+S is handled on the dialog, not as a CodeMirror keybinding: keydown
          bubbles out of the editor, so the shortcut works from the footer too.
          preventDefault even for read-only viewers, or the browser's "save page" opens. */}
      <DialogContent
        className="grid-rows-[auto_minmax(0,1fr)_auto] h-[85vh] sm:max-w-6xl"
        // Focus returns to the file's row without the ring: after typing in the editor
        // the browser treats the returned focus as keyboard focus.
        onCloseAutoFocus={(event) => {
          if (!saved.current) return;
          event.preventDefault();
          opener?.focus?.({ preventScroll: true, focusVisible: false });
        }}
        onKeyDown={(event) => {
          if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "s") {
            event.preventDefault();
            if (canEdit) save();
          }
        }}
      >
        <DialogHeader>
          <div className="flex min-w-0 items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
              <FileCode2 className="size-5" />
            </span>
            <DialogTitle className="truncate font-mono text-base">{file?.path}</DialogTitle>
          </div>
          {/* A blocked file has nothing to save, so the backups line would be wrong; screen
              readers get the reason instead. */}
          <DialogDescription className={blocked ? "sr-only" : "pt-1"}>
            {blocked ?? (canEdit ? t("editor.subtitle") : t("editor.readOnly"))}
          </DialogDescription>
        </DialogHeader>

        {loading ? (
          <div className="flex items-center justify-center rounded-lg border border-console-border bg-console">
            <Loader2 className="size-5 animate-spin text-console-muted" />
          </div>
        ) : blocked ? (
          <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed text-center">
            <p className="max-w-sm text-sm text-muted-foreground">{blocked}</p>
            <Button asChild variant="outline" size="sm">
              <a href={fileDownloadUrl(appId, file.path)} download={file.name}>
                <Download className="size-4" />
                {t("actions.download")}
              </a>
            </Button>
          </div>
        ) : (
          <div className="flex min-h-0 flex-col overflow-hidden rounded-lg border border-console-border bg-console">
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-console-border px-3 py-1.5">
              <span className="truncate font-mono text-xs text-console-muted">{file?.path}</span>
              <div className="flex shrink-0 items-center gap-1">
                {canEdit && loaded?.backups?.length ? (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setRestoreOpen(true)}
                    className="h-7 gap-1.5 px-2 text-xs text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
                  >
                    <History className="size-3.5" />
                    {t("restore.action")}
                  </Button>
                ) : null}
                <CopyButton
                  value={contents}
                  label={t("editor.copy")}
                  className="text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
                />
              </div>
            </div>
            <div className="min-h-0 flex-1 overflow-hidden" aria-label={file?.name}>
              <CodeEditor
                filename={file?.name}
                value={contents}
                onChange={(next) => {
                  // Editing is the retry; the previous error no longer describes what is on screen.
                  if (saveError) setSaveError(null);
                  setContents(next);
                }}
                readOnly={!canEdit}
                className="h-full"
              />
            </div>
          </div>
        )}

        {/* Between the editor and the buttons: the last thing read before Save. */}
        {saveError ? (
          <div
            role="alert"
            className="flex min-w-0 items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
          >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span className="min-w-0 break-words">{saveError}</span>
          </div>
        ) : null}

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={saving}>
            {t("cancel")}
          </Button>
          {canEdit && !blocked ? (
            <ReasonTooltip reason={!dirty && !saving && !loading ? tc("nothingToSave") : null}>
            <Button onClick={save} disabled={!dirty || saving || loading}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("editor.save")}
              {/* Inside the button, as this action's shortcut. Hidden while saving so it does
                  not invite a second press. */}
              {saving ? null : (
                <ShortcutHint letter="S" className="ms-1 border-primary-foreground/25 bg-primary-foreground/15 text-primary-foreground/80" />
              )}
            </Button>
            </ReasonTooltip>
          ) : null}
        </DialogFooter>
      </DialogContent>

      {canEdit && loaded?.backups?.length ? (
        <RestoreFileBackupDialog
          appId={appId}
          path={file.path}
          backups={loaded.backups}
          open={restoreOpen}
          onOpenChange={setRestoreOpen}
          onRestored={() => {
            reloadAfterRestore();
            router.refresh();
          }}
        />
      ) : null}

      <ConfirmDialog
        open={discardOpen}
        onOpenChange={setDiscardOpen}
        icon={TriangleAlert}
        tone="warning"
        title={t("editor.discardTitle")}
        description={t("editor.discardDescription")}
        cancelLabel={t("editor.keepEditing")}
        confirmLabel={t("editor.discardConfirm")}
        confirmVariant="destructive"
        onConfirm={confirmDiscard}
      />
    </Dialog>
  );
}
