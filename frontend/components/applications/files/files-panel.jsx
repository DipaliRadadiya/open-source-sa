"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import Link from "@/components/ui/app-link";
import { FolderPlus, FilePlus, UploadCloud, Folder, SearchX, Globe, Trash2, Eye, EyeOff, MousePointerClick } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { FileBreadcrumb } from "@/components/applications/files/file-breadcrumb";
import { FilesTable } from "@/components/applications/files/files-table";
import { SizeBreakdownSheet } from "@/components/applications/files/size-breakdown-sheet";
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
import { folderSize } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";

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
  // A file named in the link (`?open=`), opened on arrival.
  openName = null,
}) {
  const t = useTranslations("applications.files");
  const opened = openName
    ? (initialFiles ?? []).find((f) => f.name === openName && f.type !== "dir")
    : null;
  const openedFile = opened ? { ...opened, path: joinPath(initialPath ?? "", opened.name) } : null;
  // An image opens in the preview, text in the editor; anything else (an archive,
  // a binary) cannot be opened, so its row is highlighted instead.
  const [action, setAction] = useState(() =>
    openedFile && canManage && canOpenFile(openedFile.name)
      ? { type: isImageFile(openedFile.name) ? "preview" : "edit", file: openedFile }
      : null,
  ); // { type, file }
  const [newFolderOpen, setNewFolderOpen] = useState(false);
  const [newFileOpen, setNewFileOpen] = useState(false);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [droppedFiles, setDroppedFiles] = useState(null);
  const [query, setQuery] = useState("");
  const [siteSearch, setSiteSearch] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  // dragenter/dragleave fire for every child a drag crosses, so a boolean flickers;
  // a depth counter only reads "outside" back at zero.
  const dragDepth = useRef(0);

  // Which row to flash after a create/rename/copy/compress/upload lands: the list
  // re-sorts on refresh, so the result could be anywhere.
  const [highlightPath, setHighlightPath] = useState(openedFile?.path ?? null);
  const highlightTimeout = useRef(null);
  useEffect(() => () => clearTimeout(highlightTimeout.current), []);

  // `open` is dropped from the URL once the file is shown, without a navigation, so
  // a refresh or Back does not reopen it.
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

  // Server-provided on every navigation/refresh; a directory listing has no live
  // state to poll. The path segment keys the server component's fetch.
  const path = initialPath ?? "";
  const files = (initialFiles ?? []).map((f) => ({ ...f, path: joinPath(path, f.name) }));

  // Selection is a list of paths, not row indexes: the list re-sorts and refreshes,
  // and an index would then point at a different file.
  const [selected, setSelected] = useState([]);
  const [bulkAction, setBulkAction] = useState(null);
  const [bulkOutcome, setBulkOutcome] = useState(null);

  // Leaving the folder clears the selection, so nothing unseen can be acted on.
  // Adjusted during render rather than in an effect to avoid painting one frame
  // with the old selection.
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
    // Full success: a toast is enough. Anything else stays on screen to be read.
    if (!result.failed.length) {
      setBulkOutcome(null);
      // A permanent delete must not report "moved to the trash".
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
  /*
   * Only rows on screen count as selected, so a filter or search cannot leave a
   * hidden .htaccess in the selection. Derived, not pruned: clearing the filter
   * restores the ticks.
   */
  const shownSelection = useMemo(() => {
    const onScreen = new Set(filtered.map((f) => f.path));
    return selected.filter((entry) => onScreen.has(entry));
  }, [selected, filtered]);
  const canWrite = canManage;
  const writeReason = canWrite ? null : t("noPermission");

  const hiddenHref = hiddenToggleHref({ appId, path, showHidden });
  // Remembered for the next folder too, not only this URL.
  const rememberHidden = () => writePref(HIDDEN_COOKIE, showHidden ? "hide" : null);

  // Folder sizes are computed on request and cached while the listing is shown;
  // each request makes the backend walk the tree.
  const [folderSizes, setFolderSizes] = useState({});
  // Tracks every folder being measured, so concurrent Calculates keep their own
  // spinners.
  const [sizingPaths, setSizingPaths] = useState([]);

  async function measure(file) {
    if (sizingPaths.includes(file.path)) return;
    setSizingPaths((current) => [...current, file.path]);
    try {
      const { data } = await folderSize(appId, file.path);
      setFolderSizes((current) => ({ ...current, [file.path]: data?.size_human ?? null }));
    } catch (error) {
      toast.error(apiMessage(error, t("size.failed")));
    } finally {
      setSizingPaths((current) => current.filter((entry) => entry !== file.path));
    }
  }

  function onAction(type, file) {
    // Answered in place: one number about one row, which already has a column for it.
    if (type === "size") {
      measure(file);
      return;
    }
    setAction({ type, file });
  }

  function closeAction() {
    setAction(null);
  }

  // Drop anywhere on the panel to upload; the dialog flow still works too.
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
      // A snapshot, not the live FileList: `dataTransfer` is emptied once the drop
      // event finishes, and this is read later during the dialog's render.
      setDroppedFiles(Array.from(e.dataTransfer.files));
      setUploadOpen(true);
    }
  }

  // Plain, always-labelled buttons; `flex-wrap` handles narrow screens by wrapping,
  // not by hiding labels. Grouped by job (view, add, repair, leave); Upload is the
  // only primary action.
  const addButtons = (
    <div className="flex flex-wrap items-center gap-2">
      {/* Divides "looking at this folder" from "changing it".
          
          A CONTAINER query, not a viewport one: the strip's width depends on the
          sidebar. Below the threshold the groups wrap onto separate lines and the rule
          would dangle.
          
          73rem is measured: the groups stop fitting at a 1176px strip, and container
          queries measure the content box (18px narrower). 72rem made the rule itself
          cause the wrap; 74rem hid it on rows that fit. */}
      <Separator
        orientation="vertical"
        // `!self-center` because the primitive's `data-vertical:self-stretch` beats the
        // row's `items-center` and would pin the 20px rule to the top.
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
      {/* Neither is part of adding a file. Rank comes from Upload being the one filled
          button; grey text on this strip read as disabled. */}
      <Separator orientation="vertical" className="mx-0.5 !h-5 !self-center" />
      <FixPermissionsButton appId={appId} canManage={canManage} />
      {/* Trash is reached from this toolbar, swapping the list, as in other panels.
          
          Light red tint (outline, 5% fill, red text) so it does not read as disabled,
          while staying below the solid red of truly destructive controls.
          
          Text is destructive red mixed 22% toward the foreground in light mode: plain
          `text-destructive` on this tint is under 4.5:1 for 14px text. Dark mode passes
          with the plain token. */}
      <Button
        variant="outline"
        size="sm"
        className="border-destructive/30 bg-destructive/5 text-destructive [--destructive-ink:color-mix(in_oklch,var(--destructive),var(--foreground)_22%)] text-(--destructive-ink) hover:bg-destructive/10 hover:text-(--destructive-ink) dark:border-destructive/40 dark:bg-destructive/10 dark:text-destructive dark:hover:bg-destructive/15 dark:hover:text-destructive"
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
      {/* Above the listing: queued archives are not in the rows yet. */}
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
        {/* Site search spans every folder, so a local "X of Y" count would be wrong. */}
        {files.length && !siteSearch ? (
          <span className="shrink-0 text-xs text-muted-foreground">
            {query.trim()
              ? t("itemCountFiltered", { shown: filtered.length, total: files.length })
              : t("itemCount", { count: files.length })}
          </span>
        ) : null}
      </div>

      <FileShortcuts appId={appId} siteType={siteType} path={path} onAction={onAction} canManage={canManage} />

      {/* One toolbar on one bordered surface, with search inside it, so the controls
          read as a group tied to the table. */}
      <div className="@container/toolbar flex flex-col gap-3 rounded-xl border bg-muted/30 p-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        {/* Sized to content, not `flex-1`, so the two groups wrap as wholes instead of
            squeezing each other. */}
        <div className="flex flex-wrap items-center gap-2">
          {files.length > 0 ? (
            <LocalSearchInput
              value={query}
              onChange={(next) => {
                setQuery(next);
                setSiteSearch(false);
              }}
              placeholder={t("searchPlaceholder")}
              // 224px, not the default 320: measured, the two groups otherwise overflow a
              // 1196px strip by 10px.
              className="sm:max-w-56"
            />
          ) : null}
          {/* Files change outside the panel (deploys, cron, SSH). Re-runs the server
              component, so it refreshes the trash view and search results too. Grouped with
              the view controls: none of these change anything on disk. */}
          <RefreshButton className="size-8" />
          {/* Context for the listing, not an action, so it sits with the view controls. */}
          <SizeBreakdownSheet breakdown={breakdown} />
          {/* A link, not a button: the listing is fetched on the server, so the choice must
              be in the URL (also shareable and reload-safe). */}
          {files.length > 0 || hiddenCount > 0 ? (
            <Button asChild variant="outline" size="sm">
              <Link
                href={hiddenHref}
                aria-pressed={!showHidden}
                scroll={false}
                onClick={rememberHidden}
              >
                {showHidden ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
                {showHidden ? t("hidden.hide") : t("hidden.show")}
                {/* Hidden files are counted so the screen never looks like it lost them. */}
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
      {siteSearch ? null : (
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
        /*
         * There ARE files here, just hidden: "empty" would be false. The action reuses
         * the toggle's href, so "show hidden" has one definition.
         */
        <EmptyState
          icon={EyeOff}
          title={t("empty.hiddenOnlyTitle", { count: hiddenCount })}
          description={t("empty.hiddenOnlyDescription")}
          action={
            <Button asChild variant="outline" size="sm">
              <Link href={hiddenHref} scroll={false} onClick={rememberHidden}>
                <Eye className="size-3.5" />
                {t("hidden.show")}
              </Link>
            </Button>
          }
        />
      ) : files.length === 0 ? (
        /*
         * Explain, then offer the way out (including drag-and-drop), and only actions the
         * reader may take.
         */
        <EmptyState
          icon={Folder}
          title={t("empty.title")}
          description={canWrite ? t("empty.descriptionWrite") : t("empty.descriptionReadOnly")}
          action={
            canWrite ? (
              <div className="flex flex-wrap justify-center gap-2">
                {/* Outlined: the toolbar's Upload is already the filled primary. */}
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
          title={t("empty.filteredTitle")}
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
              selected={shownSelection}
              onToggle={toggleSelected}
              // Same state the table gets, so "Folder size" in the card menu can show its answer.
              folderSizes={folderSizes}
              sizingPaths={sizingPaths}
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
              selected={shownSelection}
              onToggle={toggleSelected}
              onToggleAll={toggleAll}
              folderSizes={folderSizes}
              sizingPaths={sizingPaths}
              initialSort={initialSort}
            />
          </div>
          {/* Advertises the whole-panel drop target. Desktop only. */}
          {canWrite ? (
            <p className="hidden items-center gap-1.5 text-xs text-muted-foreground lg:flex">
              <MousePointerClick className="size-3.5" aria-hidden />
              {t("dropHint")}
            </p>
          ) : null}
        </>
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

      {/* Mounted only while active, so each dialog initializes fresh from props instead
          of resetting via setState-in-effect. */}
      {bulkAction ? (
        <BulkDialogs
          appId={appId}
          action={bulkAction}
          paths={shownSelection}
          // The rows, not just paths: the Permissions dialog starts from the actual modes.
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
