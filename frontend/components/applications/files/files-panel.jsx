"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import Link from "@/components/ui/app-link";
import { FolderPlus, FilePlus, UploadCloud, Folder, SearchX, Globe, Trash2, Eye, EyeOff, MousePointerClick } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { FileBreadcrumb } from "@/components/applications/files/file-breadcrumb";
import { FilesTable } from "@/components/applications/files/files-table";
import { SizeBreakdownSheet } from "@/components/applications/files/size-breakdown-sheet";
import { FolderSizesStatus } from "@/components/applications/files/folder-sizes-status";
import { useFolderSizes } from "@/components/applications/files/use-folder-sizes";
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
import { ArchiveJobsBanner } from "@/components/applications/files/archive-jobs-banner";
import { ExtractDialog } from "@/components/applications/files/extract-dialog";
import { PermissionsDialog } from "@/components/applications/files/permissions-dialog";
import { DeleteFileDialog } from "@/components/applications/files/delete-file-dialog";
import { FileShortcuts } from "@/components/applications/files/file-shortcuts";
import { FixPermissionsButton } from "@/components/applications/files/fix-permissions-button";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { SelectionBar } from "@/components/applications/files/selection-bar";
import { BulkDialogs } from "@/components/applications/files/bulk-dialogs";
import { BulkResultPanel } from "@/components/applications/files/bulk-result-panel";
import { joinPath } from "@/lib/files/path-helpers";
import { canOpenFile } from "@/lib/files/openable";
import { isImageFile } from "@/lib/files/file-icon";
import { hiddenToggleHref } from "@/lib/files/hidden-href";
import { HIDDEN_COOKIE, writePref } from "@/lib/files/view-prefs";

export function FilesPanel({
  appId,
  initialPath,
  initialFiles,
  hiddenCount = 0,
  showHidden = true,
  initialSort,
  canManage,
  breakdown = null,
  siteType = null,
  servingProfile = null,
  openName = null,
}) {
  const t = useTranslations("applications.files");
  const opened = openName
    ? (initialFiles ?? []).find((f) => f.name === openName && f.type !== "dir")
    : null;
  const openedFile = opened ? { ...opened, path: joinPath(initialPath ?? "", opened.name) } : null;
  const [action, setAction] = useState(() =>
    openedFile && canManage && canOpenFile(openedFile.name)
      ? { type: isImageFile(openedFile.name) ? "preview" : "edit", file: openedFile }
      : null,
  );
  const [newFolderOpen, setNewFolderOpen] = useState(false);
  const [newFileOpen, setNewFileOpen] = useState(false);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [droppedFiles, setDroppedFiles] = useState(null);
  const [query, setQuery] = useState("");
  const [siteSearch, setSiteSearch] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  const dragDepth = useRef(0);

  const [highlightPath, setHighlightPath] = useState(openedFile?.path ?? null);
  const highlightTimeout = useRef(null);
  useEffect(() => () => clearTimeout(highlightTimeout.current), []);

  useEffect(() => {
    if (!openName) return;
    const url = new URL(window.location.href);
    url.searchParams.delete("open");
    window.history.replaceState(window.history.state, "", url);
    highlightTimeout.current = setTimeout(() => setHighlightPath(null), 2000);
  }, [openName]);
  function flashPath(target) {
    if (!target) return;
    clearTimeout(highlightTimeout.current);
    setHighlightPath(target);
    highlightTimeout.current = setTimeout(() => setHighlightPath(null), 2000);
  }

  const path = initialPath ?? "";
  const files = (initialFiles ?? []).map((f) => ({ ...f, path: joinPath(path, f.name) }));

  const [selected, setSelected] = useState([]);
  const [bulkAction, setBulkAction] = useState(null);
  const [bulkOutcome, setBulkOutcome] = useState(null);

  // Adjusted during render to avoid a stale frame.
  const [selectionPath, setSelectionPath] = useState(path);
  if (selectionPath !== path) {
    setSelectionPath(path);
    setSelected([]);
    setBulkOutcome(null);
  }

  function toggleSelected(target) {
    setSelected((current) =>
      current.includes(target)
        ? current.filter((entry) => entry !== target)
        : [...current, target],
    );
  }

  function toggleAll(paths, on) {
    setSelected((current) =>
      on
        ? [...new Set([...current, ...paths])]
        : current.filter((entry) => !paths.includes(entry)),
    );
  }

  function onBulkResult(action, result, { permanent = false } = {}) {
    setSelected([]);
    if (!result.failed.length) {
      setBulkOutcome(null);
      const key = action === "delete" && permanent ? "bulk.deleteDoneForever" : `bulk.${action}Done`;
      toast.success(t(key, { count: result.succeeded.length }));
      return;
    }
    setBulkOutcome(result);
  }

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return needle ? files.filter((f) => f.name.toLowerCase().includes(needle)) : files;
  }, [files, query]);
  const shownSelection = useMemo(() => {
    const onScreen = new Set(filtered.map((f) => f.path));
    return selected.filter((entry) => onScreen.has(entry));
  }, [selected, filtered]);
  const canWrite = canManage;
  const writeReason = canWrite ? null : DENIED;

  const hiddenHref = hiddenToggleHref({ appId, path, showHidden });
  const rememberHidden = () => writePref(HIDDEN_COOKIE, showHidden ? "hide" : null);

  const hasFolders = files.some((f) => f.type === "dir");
  const sizes = useFolderSizes(appId, path, initialFiles, hasFolders);
  const folderSizes = { ...sizes.data, loading: sizes.loading };

  function onAction(type, file) {
    setAction({ type, file });
  }

  function closeAction() {
    setAction(null);
  }

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
      setDroppedFiles(Array.from(e.dataTransfer.files));
      setUploadOpen(true);
    }
  }

  const addButtons = (
    <div className="flex flex-wrap items-center gap-1.5">
      <Separator
        orientation="vertical"
        className="mx-0.5 !h-5 !self-center hidden @[73rem]/toolbar:block"
      />
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
      {canWrite ? (
        <Separator orientation="vertical" className="mx-0.5 !h-5 !self-center hidden @[52rem]/toolbar:block" />
      ) : null}
      <FixPermissionsButton appId={appId} canManage={canManage} servingProfile={servingProfile} />
      <Button
        variant="outline"
        size="sm"
        className={cn(
          "border-destructive/30 bg-destructive/5 text-destructive [--destructive-ink:color-mix(in_oklch,var(--destructive),var(--foreground)_22%)] text-(--destructive-ink) hover:bg-destructive/10 hover:text-(--destructive-ink) dark:border-destructive/40 dark:bg-destructive/10 dark:text-destructive dark:hover:bg-destructive/15 dark:hover:text-destructive",
          "in-[.bg-card]:border-destructive/30 in-[.bg-card]:bg-destructive/5 in-[.bg-card]:text-(--destructive-ink) in-[.bg-card]:hover:bg-destructive/10 in-[.bg-card]:dark:border-destructive/40 in-[.bg-card]:dark:bg-destructive/10 in-[.bg-card]:dark:text-destructive in-[.bg-card]:dark:hover:bg-destructive/15",
          "in-[.bg-card]:hover:border-destructive/40 in-[.bg-card]:hover:text-[color-mix(in_oklch,var(--destructive),var(--foreground)_22%)] in-[.bg-card]:dark:hover:border-destructive/50 in-[.bg-card]:dark:hover:text-destructive",
        )}
        asChild
      >
        <Link href={`/applications/${appId}/files?trash=1`} prefetch={false}>
          <Trash2 className="size-3.5" />
          {t("trash.action")}
        </Link>
      </Button>
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
      <ArchiveJobsBanner appId={appId} />
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
        {files.length && !siteSearch ? (
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
            {hasFolders ? <FolderSizesStatus sizes={sizes} /> : null}
            <span className="shrink-0">
              {query.trim()
                ? t("itemCountFiltered", { shown: filtered.length, total: files.length })
                : t("itemCount", { count: files.length })}
            </span>
          </div>
        ) : null}
      </div>

      <FileShortcuts appId={appId} siteType={siteType} path={path} onAction={onAction} canManage={canManage} />

      <div className="@container/toolbar flex flex-col gap-2 rounded-2xl border border-border/70 bg-card p-2.5 shadow-e1 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-1.5">
          {files.length > 0 ? (
            <LocalSearchInput
              value={query}
              onChange={(next) => {
                setQuery(next);
                setSiteSearch(false);
              }}
              placeholder={t("searchPlaceholder")}
              className="sm:max-w-44"
            />
          ) : null}
          <RefreshButton className="size-8" />
          <SizeBreakdownSheet breakdown={breakdown} />
          {/* A link: the listing is fetched on the server, so the choice has to be in the URL. */}
          {files.length > 0 || hiddenCount > 0 ? (
            <Button asChild variant="outline" size="sm">
              <Link
                href={hiddenHref}
                prefetch={false}
                scroll={false}
                onClick={rememberHidden}
              >
                {showHidden ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
                {showHidden ? t("hidden.hide") : t("hidden.show")}
                {!showHidden && hiddenCount > 0 ? (
                  <span className="ms-1 tabular-nums">
                    {t("hidden.count", { count: hiddenCount })}
                  </span>
                ) : null}
              </Link>
            </Button>
          ) : null}
        </div>
        {addButtons}
      </div>

      <BulkResultPanel result={bulkOutcome} onDismiss={() => setBulkOutcome(null)} />
      {siteSearch || !canManage ? null : (
        <SelectionBar
          selected={shownSelection}
          canManage={canManage}
          onClear={() => setSelected([])}
          onAction={setBulkAction}
        />
      )}

      {siteSearch ? (
        <SiteSearchResults appId={appId} query={query} onAction={onAction} canManage={canManage} />
      ) : files.length === 0 && !showHidden && hiddenCount > 0 ? (
        <EmptyState
          icon={EyeOff}
          title={t("empty.hiddenOnlyTitle", { count: hiddenCount })}
          description={t("empty.hiddenOnlyDescription")}
          action={
            <Button asChild variant="outline" size="sm">
              <Link href={hiddenHref} prefetch={false} scroll={false} onClick={rememberHidden}>
                <Eye className="size-3.5" />
                {t("hidden.show")}
              </Link>
            </Button>
          }
        />
      ) : files.length === 0 ? (
        <EmptyState
          icon={Folder}
          title={t("empty.title")}
          description={canWrite ? t("empty.descriptionWrite") : t("empty.descriptionReadOnly")}
          action={
            canWrite ? (
              <div className="flex flex-wrap justify-center gap-2">
                <Button variant="outline" size="sm" onClick={() => setUploadOpen(true)}>
                  <UploadCloud className="size-3.5" />
                  {t("uploadDialog.action")}
                </Button>
                <Button variant="outline" size="sm" onClick={() => setNewFolderOpen(true)}>
                  <FolderPlus className="size-3.5" />
                  {t("newFolder.action")}
                </Button>
                <Button variant="outline" size="sm" onClick={() => setNewFileOpen(true)}>
                  <FilePlus className="size-3.5" />
                  {t("newFile.action")}
                </Button>
              </div>
            ) : null
          }
        />
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={SearchX}
          subject={Folder}
          title={t("empty.filteredTitle")}
          action={
            <Button variant="outline" size="sm" onClick={() => setSiteSearch(true)}>
              <Globe className="size-3.5" />
              {t("siteSearch.action", { query })}
            </Button>
          }
        />
      ) : (
        <div className="@container">
          <div className="@min-[900px]/page:hidden">
            <FilesCards
              appId={appId}
              data={filtered}
              canManage={canManage}
              onAction={onAction}
              highlightPath={highlightPath}
              selected={shownSelection}
              onToggle={toggleSelected}
              folderSizes={folderSizes}
            />
          </div>
          <div className="hidden @min-[900px]/page:block">
            <FilesTable
              appId={appId}
              path={path}
              data={filtered}
              canManage={canManage}
              onAction={onAction}
              highlightPath={highlightPath}
              selected={shownSelection}
              onToggle={toggleSelected}
              onToggleAll={toggleAll}
              folderSizes={folderSizes}
              initialSort={initialSort}
            />
          </div>
          {canWrite ? (
            <p className="hidden items-center gap-1.5 text-xs text-muted-foreground lg:flex">
              <MousePointerClick className="size-3.5" aria-hidden />
              {t("dropHint")}
            </p>
          ) : null}
        </div>
      )}

      <NewFolderDialog
        appId={appId}
        path={path}
        existingNames={files.map((f) => f.name)}
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
        existingNames={files.map((f) => f.name)}
        onSuccess={flashPath}
      />

      {/* Mounted only while active so each dialog starts fresh from props. */}
      {bulkAction ? (
        <BulkDialogs
          appId={appId}
          action={bulkAction}
          paths={shownSelection}
          files={files}
          path={path}
          onOpenChange={(open) => !open && setBulkAction(null)}
          onResult={onBulkResult}
        />
      ) : null}

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
          existingPaths={files.map((f) => f.path)}
          open
          onOpenChange={(open) => !open && closeAction()}
          onSuccess={flashPath}
        />
      ) : null}
      {action?.type === "compress" ? (
        <CompressDialog
          appId={appId}
          file={action.file}
          existingPaths={files.map((f) => f.path)}
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
