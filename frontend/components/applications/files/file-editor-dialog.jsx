import { useEffect, useState } from "react";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FileCode2, Loader2, History, Download, TriangleAlert } from "lucide-react";
import { getFileContent, saveFileContent, fileDownloadUrl } from "@/lib/api/files";
import { fileContentSchema } from "@/lib/schemas/file";
import { apiMessage } from "@/lib/api/error-message";
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

// CodeMirror + its language packages are a meaningful chunk of JS that only
// this dialog needs — dynamic-imported so the rest of the Files feature
// doesn't pay for it until a file is actually opened.
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
 * View/edit one text file. Same console surface as the .env and php.ini
 * editors — a machine's own file, not a form field. Loaded fresh every time
 * it opens: the list only ever gave us name/size/modified, never content.
 */
export function FileEditorDialog({ appId, file, canManage, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const router = useRouter();
  // Mounted fresh per file (see files-panel.jsx), so these start at the
  // "about to load" state directly rather than being reset by an effect.
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [loaded, setLoaded] = useState(null); // { path, content, backups }
  const [contents, setContents] = useState("");
  // Set when the file can't be opened here at all — too big or looks binary.
  // The API's own message names which; Download is the honest way out.
  const [blocked, setBlocked] = useState(null);
  const [restoreOpen, setRestoreOpen] = useState(false);
  const [discardOpen, setDiscardOpen] = useState(false);

  useEffect(() => {
    let active = true;
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
        const errors = error.response?.data?.errors;
        const tooLarge = errors?.["application.file_too_large"];
        const notText = errors?.["application.file_not_text"];
        if (tooLarge || notText) {
          setBlocked(tooLarge ? t("editor.tooLarge") : t("editor.notText"));
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [file.path]);

  const dirty = loaded !== null && contents !== loaded.content;
  const canEdit = canManage;

  async function save() {
    if (!dirty || saving) return;
    setSaving(true);
    try {
      await saveFileContent(appId, file.path, contents);
      toast.success(t("editor.saved"));
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("editor.saveFailed")));
    } finally {
      setSaving(false);
    }
  }

  // Escape, the backdrop and Cancel all funnel through here (Radix calls
  // onOpenChange(false) for all three) — one guard covers every way to close.
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
      {/* Sized to the viewport, not to a number. Editing a file is the whole
          task while this is open — a fixed 320px window over someone's
          wp-config or nginx conf shows about fifteen lines, so every read means
          scrolling a peephole. The three rows are header / editor / footer, and
          `minmax(0,1fr)` is what lets the middle one actually shrink so the
          editor scrolls internally instead of pushing the footer off-screen. */}
      {/* Cmd+S on macOS, Ctrl+S elsewhere. One handler on the dialog rather
          than a CodeMirror keybinding: keydown bubbles out of the editor (it
          binds nothing to Mod-S, so it neither handles nor stops the event),
          which means the same shortcut works with the caret in the file and
          with focus on the footer. Same shape as the .env editor's.

          preventDefault regardless of permission — otherwise a read-only viewer
          pressing Ctrl+S gets the browser's "save page" dialog over the panel,
          which is the thing this is displacing. */}
      <DialogContent
        className="grid-rows-[auto_minmax(0,1fr)_auto] h-[85vh] sm:max-w-6xl"
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
          <DialogDescription className="pt-1">
            {canEdit ? t("editor.subtitle") : t("editor.readOnly")}
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
              <span className="truncate font-mono text-[11px] text-console-muted">{file?.path}</span>
              <div className="flex shrink-0 items-center gap-1">
                {canEdit && loaded?.backups?.length ? (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setRestoreOpen(true)}
                    className="h-7 gap-1.5 px-2 text-[11px] text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
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
                onChange={setContents}
                readOnly={!canEdit}
                className="h-full"
              />
            </div>
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={saving}>
            {t("cancel")}
          </Button>
          {canEdit && !blocked ? (
            <ReasonTooltip reason={!dirty && !saving && !loading ? tc("nothingToSave") : null}>
            <Button onClick={save} disabled={!dirty || saving || loading}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("editor.save")}
              {/* Inside the button, not beside it: this is the shortcut FOR
                  this action, and a chip floating in the footer would read as
                  belonging to the footer. Dropped while saving so it does not
                  invite a second press mid-write. */}
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
          onRestored={(next) => {
            if (next?.content !== undefined) {
              setContents(next.content);
              setLoaded((prev) => ({ ...prev, content: next.content }));
            }
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
