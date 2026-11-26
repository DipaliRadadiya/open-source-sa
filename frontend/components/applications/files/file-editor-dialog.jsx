import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FileCode2, Loader2, History, Download, TriangleAlert, RotateCw } from "lucide-react";
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
import { Caution } from "@/components/ui/caution";
import { SafetyCopySwitch } from "@/components/backups/safety-copy-switch";
import { RestoreFileBackupDialog } from "@/components/applications/files/restore-file-backup-dialog";

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

export function FileEditorDialog({ appId, file, canManage, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [opener] = useState(() => (typeof document === "undefined" ? null : document.activeElement));
  const saved = useRef(false);
  const tooLarge = file.size > EDITOR_MAX_BYTES;
  const [loading, setLoading] = useState(!tooLarge);
  const [saving, setSaving] = useState(false);
  const [loaded, setLoaded] = useState(null);
  const [contents, setContents] = useState("");
  const [blocked, setBlocked] = useState(() => (tooLarge ? t("editor.tooLarge") : null));
  const [saveError, setSaveError] = useState(null);
  const [stale, setStale] = useState(false);
  const [restoreOpen, setRestoreOpen] = useState(false);
  const [discardOpen, setDiscardOpen] = useState(false);
  const [safetyCopy, setSafetyCopy] = useState(true);

  async function reloadAfterRestore() {
    setLoading(true);
    setSaveError(null);
    setStale(false);
    try {
      const { data } = await getFileContent(appId, file.path);
      const parsed = fileContentSchema.safeParse(data);
      if (!parsed.success) throw new Error("unreadable");
      setLoaded(parsed.data);
      setContents(parsed.data.content);
    } catch (error) {
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
        const response = error.response;
        if (response?.status === 422 && !response.data?.errors?.path) {
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
    // `t`, `onOpenChange` and setters stay out: a new translator identity would abort
    // the in-flight request.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [appId, file.path, tooLarge]);

  const dirty = loaded !== null && contents !== loaded.content;
  const canEdit = canManage;

  async function save() {
    if (!dirty || saving || stale) return;
    setSaving(true);
    setSaveError(null);
    try {
      await saveFileContent(appId, file.path, contents, loaded?.version, { safetyCopy });
      await refreshAndWait();
      toast.success(t("editor.saved"));
      saved.current = true;
      onOpenChange?.(false);
    } catch (error) {
      setSaveError(apiMessage(error, t("editor.saveFailed")));
      setStale(error.response?.status === 409 && error.response?.data?.reason === "changed_on_disk");
    } finally {
      setSaving(false);
    }
  }

  // Radix calls this for Escape, the backdrop and Cancel.
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
      <DialogContent
        className="grid-rows-[auto_minmax(0,1fr)_auto] h-[85vh] sm:max-w-6xl"
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
          <DialogDescription className={blocked ? "sr-only" : "pt-1"}>
            {blocked ?? (canEdit ? t(safetyCopy ? "editor.subtitle" : "editor.subtitleNoCopy") : t("editor.readOnly"))}
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
                  if (saveError && !stale) setSaveError(null);
                  setContents(next);
                }}
                readOnly={!canEdit}
                className="h-full"
              />
            </div>
          </div>
        )}

        {saveError ? (
          <div
            role="alert"
            className="flex min-w-0 items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
          >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span className="min-w-0 flex-1 break-words">{saveError}</span>
            {stale ? (
              <Button variant="outline" size="sm" className="-my-1 shrink-0 text-foreground" onClick={reloadAfterRestore}>
                <RotateCw className="size-4" />
                {t("editor.reload")}
              </Button>
            ) : null}
          </div>
        ) : null}

        {canEdit && !blocked && !loading && !safetyCopy ? (
          <Caution tone="destructive">
            <p>{tc("keepFileCopyOff")}</p>
          </Caution>
        ) : null}

        <DialogFooter>
          {canEdit && !blocked && !loading ? (
            <SafetyCopySwitch
              id="file-safety-copy"
              compact
              checked={safetyCopy}
              onCheckedChange={setSafetyCopy}
              disabled={saving}
              label={tc("keepFileCopy")}
              className="w-full sm:me-auto sm:w-fit"
            />
          ) : null}
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={saving}>
            {t("cancel")}
          </Button>
          {canEdit && !blocked ? (
            <ReasonTooltip reason={stale ? t("editor.reloadFirst") : !dirty && !saving && !loading ? tc("nothingToSave") : null}>
            <Button onClick={save} disabled={!dirty || saving || loading || stale}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("editor.save")}
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
