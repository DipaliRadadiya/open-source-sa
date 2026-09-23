"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import Link from "next/link";
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
import { hiddenToggleHref } from "@/lib/files/hidden-href";
import { folderSize } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";

export function FilesPanel({
  appId,
  initialPath,
  initialFiles,
  hiddenCount = 0,
  showHidden = true,
  canManage,
  breakdown = null,
  siteType = null,
}) {
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

  // Selection is a list of paths, not row indexes: the list re-sorts and
  // refreshes under you, and an index would then point at a different file.
  const [selected, setSelected] = useState([]);
  const [bulkAction, setBulkAction] = useState(null);
  const [bulkOutcome, setBulkOutcome] = useState(null);

  // Leaving the folder abandons the selection — carrying it across folders
  // would let someone act on things they can no longer see. Adjusted during
  // render rather than in an effect: an effect would paint one frame with the
  // old folder's selection still ticked, and React re-runs this immediately
  // without committing that frame.
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
    // Everything worked — a toast is enough, and the panel stays out of the
    // way. Anything else is left on screen to be read.
    if (!result.failed.length) {
      setBulkOutcome(null);
      // A permanent delete must not report itself as "moved to the trash" —
      // it is the one delete nothing can undo.
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
  const canWrite = canManage;
  const writeReason = canWrite ? null : t("noPermission");

  const hiddenHref = hiddenToggleHref({ appId, path, showHidden });

  // Folder sizes are computed one at a time, on request, and remembered for
  // as long as the listing is on screen — asking twice for the same folder
  // makes the backend walk the tree twice for an answer we already have.
  const [folderSizes, setFolderSizes] = useState({});
  const [sizingPath, setSizingPath] = useState(null);

  async function measure(file) {
    setSizingPath(file.path);
    try {
      const { data } = await folderSize(appId, file.path);
      setFolderSizes((current) => ({ ...current, [file.path]: data?.size_human ?? null }));
    } catch (error) {
      toast.error(apiMessage(error, t("size.failed")));
    } finally {
      setSizingPath(null);
    }
  }

  function onAction(type, file) {
    // Answered in place rather than in a dialog: it is one number about one
    // row, and the row already has a column for it.
    if (type === "size") {
      measure(file);
      return;
    }
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
      // A snapshot, not the live FileList: `dataTransfer` is neutered once the
      // drop event finishes, and this is read later — during the dialog's
      // render, by which time the live list is empty.
      setDroppedFiles(Array.from(e.dataTransfer.files));
      setUploadOpen(true);
    }
  }

  // Plain, always-labeled buttons. A dropdown would save space that desktop
  // doesn't need and costs an extra click every single time; icon-only would
  // save space mobile doesn't have room to spend on guessing. `flex-wrap`
  // handles the actual space constraint (narrow mobile) by letting the row
  // become two, not by hiding what anything is.
  //
  // Grouped rather than listed: these eight controls do four unrelated jobs —
  // looking at the folder, adding to it, repairing it, leaving it — and drawn
  // as one flat row of equal pills they read as a pile. Upload is the only
  // primary action here and used to sit sixth, weighted the same as Trash.
  const addButtons = (
    <div className="flex flex-wrap items-center gap-2">
      {/* Divides "looking at this folder" from "changing it". Without it the
          eight controls read as one continuous run however they are weighted —
          which is the actual complaint.

          Shown on a CONTAINER query, not a viewport one: the strip is narrower
          than the window by whatever the sidebar takes, so a `2xl:` guess
          would be wrong on a collapsed sidebar and wrong again on a wide one.
          Below the threshold the groups sit on separate lines, where the break
          already separates them and this would be a rule dangling at the start
          of a row.

          73rem is measured, not chosen. The groups stop fitting on one line at
          a strip width of 1176px, and a container query measures the CONTENT
          box — 18px less than the border box this is drawn on. 72rem let the
          rule appear at 1176 and its own 17px then caused the very wrap it
          exists to avoid; 74rem held it back until 1216 and lost the divider
          on a perfectly good single row at 1196. */}
      <Separator
        orientation="vertical"
        // `!self-center` because the primitive sets `data-vertical:self-stretch`,
        // which beats the row's `items-center`: stretched to the line box and
        // then clamped to 20px, the rule pinned to the TOP and sat 6px above
        // every button beside it.
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
      {/* Neither of these is part of adding a file: one repairs the folder,
          one leaves it for another view. Separated from the three above; the
          rank comes from Upload being the one filled button, not from greying
          these — grey text on this strip read as disabled. */}
      <Separator orientation="vertical" className="mx-0.5 !h-5 !self-center" />
      <FixPermissionsButton appId={appId} canManage={canManage} />
      {/* Every panel that has a trash reaches it from this toolbar — cPanel and
          Plesk both use a button here that swaps the list. Nobody gives it its
          own page, and a tab would compete with the breadcrumb.

          Light red, by Krishna's call (2026-09-23), after the grey version
          read as disabled. It is only a tint — outline, 5% fill, red text —
          so it still sits below the solid red of the controls that actually
          destroy: Delete in the selection bar and the permanent-delete
          confirm. The trade-off was raised and chosen knowingly: a red
          control that is safe to press slightly dulls what red means.

          The text is the destructive red mixed 22% toward the foreground in
          light mode: plain `text-destructive` on this tint measured 4.24:1
          from the rendered pixels, under the 4.5:1 floor for 14px text. Dark
          mode already measured 5.78:1 and keeps the plain token. */}
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

      <FileShortcuts appId={appId} siteType={siteType} path={path} onAction={onAction} />

      {/*
        One toolbar on one surface, instead of eight controls floating on the
        page over two rows.

        The border and tint are doing real work: with nothing containing them
        the pills had no relationship to each other or to the table they act
        on, which is most of why the page read as unstructured rather than
        merely busy. Search sits inside it too — on its own row it cost a whole
        band of vertical space above the fold to hold one input.
      */}
      <div className="@container/toolbar flex flex-col gap-3 rounded-xl border bg-muted/30 p-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        {/* Sized to its contents, not `flex-1`: growing this group squeezed
            the one beside it and pushed "Hide hidden files" onto a second
            line while ~500px sat empty between them. When the row genuinely
            runs out of width the two groups wrap as wholes, which is the
            behaviour worth having on a phone. */}
        <div className="flex flex-wrap items-center gap-2">
          {files.length > 0 ? (
            <LocalSearchInput
              value={query}
              onChange={(next) => {
                setQuery(next);
                setSiteSearch(false);
              }}
              placeholder={t("searchPlaceholder")}
              // 224px, not the default 320: measured, the two groups came to
              // 1206px in a 1196px strip and wrapped over 10px. The field is
              // still wider than the longest folder name anyone types into it.
              className="sm:max-w-56"
            />
          ) : null}
          {/* Files change from outside the panel — a deploy, a cron job,
              someone on SSH — so the list can be stale without anything here
              having happened. It re-runs the server component, so it refreshes
              the trash view and a search result too, not just a listing.
              Grouped with search and the view toggles because it is one of
              them: none of these four change a single byte on disk. */}
          <RefreshButton className="size-8" />
          {/* Context for the listing, not an action on it — so it sits with the
              other view controls rather than among New folder and Upload. It
              was a 340px rail beside the table until the table needed that
              width back. */}
          <SizeBreakdownSheet breakdown={breakdown} />
          {/* A link, not a button: the listing is fetched on the server, so
              the choice has to be in the URL to change what comes back. It
              also makes the view shareable and survives a reload. */}
          {files.length > 0 || hiddenCount > 0 ? (
            <Button asChild variant="outline" size="sm">
              <Link
                href={hiddenHref}
                aria-pressed={!showHidden}
                scroll={false}
              >
                {showHidden ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
                {showHidden ? t("hidden.hide") : t("hidden.show")}
                {/* The count comes from the same read that produced the rows.
                    A screen that hides files without saying how many is
                    indistinguishable from one that lost them. */}
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
          selected={selected}
          canManage={canManage}
          onClear={() => setSelected([])}
          onAction={setBulkAction}
        />
      )}

      {siteSearch ? (
        <SiteSearchResults appId={appId} query={query} onAction={onAction} />
      ) : files.length === 0 && !showHidden && hiddenCount > 0 ? (
        /*
         * There ARE files here — they are just hidden.
         *
         * "This folder is empty. Upload a file to get started." over a folder
         * holding .env, .git and .htaccess is a false statement, and the
         * suggested action is wrong too: what you want is to see them, not to
         * add another. The count has always been on screen in the toggle above
         * ("3 hidden"); only the state below it was ignoring it.
         *
         * The action reuses the same href the toggle uses, so there is one
         * definition of what "show hidden" means on this page.
         */
        <EmptyState
          icon={EyeOff}
          title={t("empty.hiddenOnlyTitle", { count: hiddenCount })}
          description={t("empty.hiddenOnlyDescription")}
          action={
            <Button asChild variant="outline" size="sm">
              <Link href={hiddenHref} scroll={false}>
                <Eye className="size-3.5" />
                {t("hidden.show")}
              </Link>
            </Button>
          }
        />
      ) : files.length === 0 ? (
        /*
         * Explain, then offer the way out. "Upload a file, or create a new
         * file or folder" with no buttons sent the reader back up to the
         * toolbar to find them — and told a read-only viewer to do things
         * they cannot. Drag-and-drop was the other undiscovered half: it
         * worked on this very area and nothing said so.
         */
        <EmptyState
          icon={Folder}
          title={t("empty.title")}
          description={canWrite ? t("empty.descriptionWrite") : t("empty.descriptionReadOnly")}
          action={
            canWrite ? (
              <div className="flex flex-wrap justify-center gap-2">
                {/* Outlined, not the filled primary: the toolbar's Upload is
                    already on screen, and two blue buttons would stop "the blue
                    one" meaning anything. */}
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
              selected={selected}
              onToggle={toggleSelected}
              // Same state the table gets — "Folder size" is in the card menu
              // too, and without these it had nowhere to put its answer.
              folderSizes={folderSizes}
              sizingPath={sizingPath}
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
              selected={selected}
              onToggle={toggleSelected}
              onToggleAll={toggleAll}
              folderSizes={folderSizes}
              sizingPath={sizingPath}
            />
          </div>
          {/* The drop target has always been this whole panel; nothing said
              so until something was already being dragged over it. Desktop
              only — a phone has nothing to drag from. */}
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

      {/* Mounted only while active, not always-mounted + an open flag — each
          dialog then initializes its own state fresh from props instead of
          resetting via a setState-in-effect on every action change. */}
      {bulkAction ? (
        <BulkDialogs
          appId={appId}
          action={bulkAction}
          paths={selected}
          // The rows themselves, not just their paths: the Permissions dialog
          // has to start from what is actually set, and a path cannot say.
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
