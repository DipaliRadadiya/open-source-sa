"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { FolderPlus, FilePlus, UploadCloud, Folder, SearchX, Globe } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { FileBreadcrumb } from "@/components/applications/files/file-breadcrumb";
import { FilesTable } from "@/components/applications/files/files-table";
import { FilesCards } from "@/components/applications/files/files-cards";
import { SiteSearchResults } from "@/components/applications/files/site-search-results";
import { NewFolderDialog } from "@/components/applications/files/new-folder-dialog";
import { NewFileDialog } from "@/components/applications/files/new-file-dialog";
import { UploadDialog } from "@/components/applications/files/upload-dialog";
import { FileEditorDialog } from "@/components/applications/files/file-editor-dialog";
import { ImagePreviewDialog } from "@/components/applications/files/image-preview-dialog";
import { RenameDialog } from "@/components/applications/files/rename-dialog";
import { CopyDialog } from "@/components/applications/files/copy-dialog";
import { CompressDialog } from "@/components/applications/files/compress-dialog";
import { ExtractDialog } from "@/components/applications/files/extract-dialog";
import { PermissionsDialog } from "@/components/applications/files/permissions-dialog";
import { DeleteFileDialog } from "@/components/applications/files/delete-file-dialog";
import { FixPermissionsButton } from "@/components/applications/files/fix-permissions-button";
import { joinPath } from "@/lib/files/path-helpers";

export function FilesPanel({ appId, initialPath, initialFiles, canManage }) {
  const t = useTranslations("applications.files");
  const [action, setAction] = useState(null); // { type, file }
  const [newFolderOpen, setNewFolderOpen] = useState(false);
  const [newFileOpen, setNewFileOpen] = useState(false);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [droppedFiles, setDroppedFiles] = useState(null);
  const [query, setQuery] = useState("");
  const [siteSearch, setSiteSearch] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  // dragenter/dragleave fire for every child element a drag crosses, not just
  // the container's own edge — a plain boolean flickers the overlay on and
  // off as the pointer passes over rows. A depth counter only reads "outside"
  // once it actually returns to zero.
  const dragDepth = useRef(0);

  // Which row to flash after a create/rename/copy/compress/upload lands —
  // the list re-sorts on refresh, so without this the result could be
  // anywhere and there's no visual answer to "did that work, and where did
  // it go."
  const [highlightPath, setHighlightPath] = useState(null);
  const highlightTimeout = useRef(null);
  useEffect(() => () => clearTimeout(highlightTimeout.current), []);
  function flashPath(target) {
    if (!target) return;
    clearTimeout(highlightTimeout.current);
    setHighlightPath(target);
    highlightTimeout.current = setTimeout(() => setHighlightPath(null), 2000);
  }

  // Server-provided on every navigation/refresh — no client polling here
  // (unlike Workers/Services), since a directory listing has no live state to
  // poll for. The path segment is what the server component keys its fetch on.
  const path = initialPath ?? "";
  const files = (initialFiles ?? []).map((f) => ({ ...f, path: joinPath(path, f.name) }));

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return needle ? files.filter((f) => f.name.toLowerCase().includes(needle)) : files;
  }, [files, query]);
  const canWrite = canManage;
  const writeReason = canWrite ? null : t("noPermission");

  function onAction(type, file) {
    setAction({ type, file });
  }

  function closeAction() {
    setAction(null);
  }

  // Drop anywhere on the panel to upload — the button-first flow (open
  // dialog, then drag in) still works too, this just skips the first click.
  function onDragEnter(e) {
    if (!canWrite) return;
    e.preventDefault();
    dragDepth.current += 1;
    setDragOver(true);
  }
  function onDragOver(e) {
    if (!canWrite) return;
    e.preventDefault();
  }
  function onDragLeave(e) {
    if (!canWrite) return;
    e.preventDefault();
    dragDepth.current = Math.max(0, dragDepth.current - 1);
    if (dragDepth.current === 0) setDragOver(false);
  }
  function onDrop(e) {
    if (!canWrite) return;
    e.preventDefault();
    dragDepth.current = 0;
    setDragOver(false);
    if (e.dataTransfer.files?.length) {
      setDroppedFiles(e.dataTransfer.files);
      setUploadOpen(true);
    }
  }

  // Four plain, always-labeled buttons. A dropdown would save space that
  // desktop doesn't need and costs an extra click every single time; icon-
  // only would save space mobile doesn't have room to spend on guessing.
  // `flex-wrap` handles the actual space constraint (narrow mobile) by
  // letting the row become two, not by hiding what anything is.
  const addButtons = (
    <div className="flex flex-wrap items-center gap-2">
      <ReasonTooltip reason={writeReason}>
        <Button variant="outline" size="sm" disabled={!canWrite} onClick={() => setNewFolderOpen(true)}>
          <FolderPlus className="size-3.5" />
          {t("newFolder.action")}
        </Button>
      </ReasonTooltip>
      <ReasonTooltip reason={writeReason}>
        <Button variant="outline" size="sm" disabled={!canWrite} onClick={() => setNewFileOpen(true)}>
          <FilePlus className="size-3.5" />
          {t("newFile.action")}
        </Button>
      </ReasonTooltip>
      <ReasonTooltip reason={writeReason}>
        <Button size="sm" disabled={!canWrite} onClick={() => setUploadOpen(true)}>
          <UploadCloud className="size-3.5" />
          {t("uploadDialog.action")}
        </Button>
      </ReasonTooltip>
      <FixPermissionsButton appId={appId} canManage={canManage} />
    </div>
  );

  return (
    <div
      className="relative space-y-4"
      onDragEnter={onDragEnter}
      onDragOver={onDragOver}
      onDragLeave={onDragLeave}
      onDrop={onDrop}
    >
      {dragOver ? (
        <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-2xl border-2 border-dashed border-primary bg-primary/5 backdrop-blur-[1px]">
          <p className="flex items-center gap-2 rounded-lg bg-background px-4 py-2 text-sm font-medium shadow-lg">
            <UploadCloud className="size-4 text-primary" />
            {t("uploadDialog.dropOverlay")}
          </p>
        </div>
      ) : null}

      <div className="flex flex-wrap items-center justify-between gap-2">
        <FileBreadcrumb appId={appId} path={path} />
        {/* Site search results span every folder on the site, not just this
            one — a local "X of Y" count here would describe the wrong
            thing, so it's hidden rather than showing a stale local number. */}
        {files.length && !siteSearch ? (
          <span className="shrink-0 text-xs text-muted-foreground">
            {query.trim()
              ? t("itemCountFiltered", { shown: filtered.length, total: files.length })
              : t("itemCount", { count: files.length })}
          </span>
        ) : null}
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        {files.length > 0 ? (
          <LocalSearchInput
            value={query}
            onChange={(next) => {
              setQuery(next);
              setSiteSearch(false);
            }}
            placeholder={t("searchPlaceholder")}
          />
        ) : (
          <div />
        )}
        {addButtons}
      </div>

      {siteSearch ? (
        <SiteSearchResults appId={appId} query={query} onAction={onAction} />
      ) : files.length === 0 ? (
        <EmptyState icon={Folder} title={t("empty.title")} description={t("empty.description")} />
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={SearchX}
          title={t("empty.filteredTitle")}
          description={t("empty.filteredDescription")}
          action={
            <Button variant="outline" size="sm" onClick={() => setSiteSearch(true)}>
              <Globe className="size-3.5" />
              {t("siteSearch.action", { query })}
            </Button>
          }
        />
      ) : (
        <>
          <div className="lg:hidden">
            <FilesCards
              appId={appId}
              data={filtered}
              canManage={canManage}
              onAction={onAction}
              highlightPath={highlightPath}
            />
          </div>
          <div className="hidden lg:block">
            <FilesTable
              appId={appId}
              path={path}
              data={filtered}
              canManage={canManage}
              onAction={onAction}
              highlightPath={highlightPath}
            />
          </div>
        </>
      )}

      <NewFolderDialog
        appId={appId}
        path={path}
        open={newFolderOpen}
        onOpenChange={setNewFolderOpen}
        onSuccess={flashPath}
      />
      <NewFileDialog
        appId={appId}
        path={path}
        open={newFileOpen}
        onOpenChange={setNewFileOpen}
        onSuccess={flashPath}
      />
      <UploadDialog
        appId={appId}
        path={path}
        open={uploadOpen}
        onOpenChange={(next) => {
          setUploadOpen(next);
          if (!next) setDroppedFiles(null);
        }}
        initialFiles={droppedFiles}
        onSuccess={flashPath}
      />

      {/* Mounted only while active, not always-mounted + an open flag — each
          dialog then initializes its own state fresh from props instead of
          resetting via a setState-in-effect on every action change. */}
      {action?.type === "edit" ? (
        <FileEditorDialog
          appId={appId}
          file={action.file}
          canManage={canManage}
          open
          onOpenChange={(open) => !open && closeAction()}
        />
      ) : null}
      {action?.type === "preview" ? (
        <ImagePreviewDialog
          appId={appId}
          file={action.file}
          open
          onOpenChange={(open) => !open && closeAction()}
        />
      ) : null}
      {action?.type === "rename" ? (
        <RenameDialog
          appId={appId}
          file={action.file}
          open
          onOpenChange={(open) => !open && closeAction()}
          onSuccess={flashPath}
        />
      ) : null}
      {action?.type === "copy" ? (
        <CopyDialog
          appId={appId}
          file={action.file}
          open
          onOpenChange={(open) => !open && closeAction()}
          onSuccess={flashPath}
        />
      ) : null}
      {action?.type === "compress" ? (
        <CompressDialog
          appId={appId}
          file={action.file}
          open
          onOpenChange={(open) => !open && closeAction()}
          onSuccess={flashPath}
        />
      ) : null}
      {action?.type === "extract" ? (
        <ExtractDialog
          appId={appId}
          file={action.file}
          open
          onOpenChange={(open) => !open && closeAction()}
        />
      ) : null}
      {action?.type === "permissions" ? (
        <PermissionsDialog
          appId={appId}
          file={action.file}
          open
          onOpenChange={(open) => !open && closeAction()}
        />
      ) : null}
      {action?.type === "delete" ? (
        <DeleteFileDialog
          appId={appId}
          file={action.file}
          open
          onOpenChange={(open) => !open && closeAction()}
        />
      ) : null}
    </div>
  );
}
