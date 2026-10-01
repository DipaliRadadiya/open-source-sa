import { useFormatter, useTranslations } from "next-intl";
import Link from "@/components/ui/app-link";
import { Folder, Link2, Loader2, TriangleAlert, Unlink } from "lucide-react";
import { cn } from "@/lib/utils";
import { parseApiWallClock } from "@/lib/format/api-date";
import { Checkbox } from "@/components/ui/checkbox";
import { DataTable } from "@/components/ui/data-table";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { ContextMenuItem, ContextMenuSeparator } from "@/components/ui/context-menu";
import { FileRowActions } from "@/components/applications/files/file-row-actions";
import { FileActionItems } from "@/components/applications/files/file-actions-menu";
import { FileThumb } from "@/components/applications/files/file-thumb";
import { isImageFile } from "@/lib/files/file-icon";
import { canOpenFile } from "@/lib/files/openable";
import { FILE_NAME } from "@/lib/files/name-style";
import { useModeSentence } from "@/components/applications/files/use-mode-sentence";
import { isWorldWritable, symbolicMode } from "@/lib/files/describe-mode";
import { SORT_COOKIE, serializeSort, writePref } from "@/lib/files/view-prefs";

// Cells are module-level so flexRender's identity stays stable across re-renders
// (see workers-table.jsx).

// "DD-MM-YYYY HH:mm:ss" (every API date) does not sort as a string; parse to a
// number, falling back to 0 rather than throwing on an unexpected shape.
function parseApiDate(value) {
  const m = /^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})$/.exec(value ?? "");
  if (!m) return 0;
  const [, dd, mm, yyyy, hh, min, ss] = m;
  return new Date(+yyyy, +mm - 1, +dd, +hh, +min, +ss).getTime();
}

// Folders always sort above files regardless of column or direction; each column
// supplies its own tiebreaker.
function withDirsFirst(compare) {
  return (rowA, rowB) => {
    const aDir = rowA.original.type === "dir";
    const bDir = rowB.original.type === "dir";
    if (aDir !== bDir) return aDir ? -1 : 1;
    return compare(rowA.original, rowB.original);
  };
}

const sortByName = withDirsFirst((a, b) => a.name.localeCompare(b.name));
const sortBySize = withDirsFirst((a, b) => (a.size ?? 0) - (b.size ?? 0));
const sortByModified = withDirsFirst((a, b) => parseApiDate(a.modified_at) - parseApiDate(b.modified_at));

// Keyed by path, not row index, which points at the wrong file after a re-sort.
function SelectCell({ row, table }) {
  const t = useTranslations("applications.files");
  const { selected, onToggle } = table.options.meta;
  const file = row.original;
  return (
    <Checkbox
      checked={selected.includes(file.path)}
      onCheckedChange={() => onToggle(file.path)}
      aria-label={t("bulk.selectOne", { name: file.name })}
      // Folder rows are links; a tick must not follow the link.
      onClick={(event) => event.stopPropagation()}
    />
  );
}

function SelectAllHeader({ table }) {
  const t = useTranslations("applications.files");
  const { selected, onToggleAll } = table.options.meta;
  const rows = table.getRowModel().rows.map((row) => row.original.path);
  const all = rows.length > 0 && rows.every((path) => selected.includes(path));
  const some = !all && rows.some((path) => selected.includes(path));
  return (
    <Checkbox
      // "This folder" only: select-all must never reach past what is visible.
      checked={all ? true : some ? "indeterminate" : false}
      onCheckedChange={() => onToggleAll(rows, !all)}
      aria-label={t("bulk.selectAll")}
    />
  );
}

function NameCell({ row, table }) {
  const t = useTranslations("applications.files");
  const file = row.original;
  const { appId, onAction, canManage } = table.options.meta;

  if (file.type === "dir") {
    const href = `/applications/${appId}/files?path=${encodeURIComponent(file.path)}`;
    return (
      <Link
        href={href}
        className="flex min-w-0 items-center gap-2 rounded font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        <Folder className="size-4 shrink-0 text-primary" />
        <span className={FILE_NAME} title={file.name}>
          {file.name}
        </span>
      </Link>
    );
  }

  if (file.type === "symlink") {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            tabIndex={0}
            className={cn(
              "flex min-w-0 items-center gap-2 rounded",
              file.link_broken ? "text-destructive" : "text-muted-foreground",
            )}
          >
            {file.link_broken ? (
              <Unlink className="size-4 shrink-0" />
            ) : (
              <Link2 className="size-4 shrink-0" />
            )}
            <span className={FILE_NAME} title={file.name}>
              {file.name}
            </span>
            {/* Shown inline: a link's name says nothing about what it is, and a dangling one
                looks like a working one. */}
            {file.link_target ? (
              <span className="truncate font-mono text-xs text-muted-foreground/70">
                → {file.link_target}
              </span>
            ) : null}
          </span>
        </TooltipTrigger>
        <TooltipContent className="max-w-60">
          {file.link_broken ? t("symlinkBrokenHint") : t("symlinkHint")}
        </TooltipContent>
      </Tooltip>
    );
  }

  // Archives and binaries cannot be opened; opening needs manage, so view-only
  // roles get plain text, not a 403.
  if (!canManage || !canOpenFile(file.name)) {
    return (
      <span className="flex w-full min-w-0 items-center gap-2 font-medium">
        <FileThumb file={file} appId={appId} className="size-5" canPreview={canManage} />
        <span className={FILE_NAME} title={file.name}>
          {file.name}
        </span>
      </span>
    );
  }

  return (
    <button
      type="button"
      onClick={() => onAction(isImageFile(file.name) ? "preview" : "edit", file)}
      // `w-full`: a <button> sizes to its content even as a flex box, so `truncate`
      // would not clip at the cell. Folders render as an <a>, which fills the cell.
      className="flex w-full min-w-0 items-center gap-2 rounded text-left font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      <FileThumb file={file} appId={appId} className="size-5" canPreview={canManage} />
      <span className={FILE_NAME} title={file.name}>
        {file.name}
      </span>
    </button>
  );
}

function SizeCell({ row, table }) {
  const file = row.original;
  const t = useTranslations("applications.files");
  const { folderSizes = {}, sizingPaths = [], onAction } = table.options.meta;

  // A folder has no size until requested (the backend walks the tree), so the dash
  // is accurate.
  if (sizingPaths.includes(file.path)) {
    return <Loader2 className="ml-auto size-3.5 animate-spin text-muted-foreground" />;
  }

  // A folder shows only a MEASURED size: the listing's `size_human` for a directory
  // is the 4 KB directory entry itself.
  const shown = file.type === "dir" ? folderSizes[file.path] : file.size_human;
  // Offered in the size column, where the answer appears.
  if (file.type === "dir" && !shown) {
    return (
      <button
        type="button"
        onClick={() => onAction?.("size", file)}
        className="text-xs text-muted-foreground underline decoration-dotted underline-offset-2 hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        {t("size.calculate")}
      </button>
    );
  }
  return (
    <span className="tabular-nums text-muted-foreground">
      {shown ?? "—"}
    </span>
  );
}

function ModifiedCell({ row }) {
  const format = useFormatter();
  const file = row.original;
  if (!file.modified_at) return <span className="text-muted-foreground">—</span>;
  // Exact time on hover in words: the API's day-first "23-09-2026 11:28:55" reads as
  // a US date. Parsed as wall-clock so it stays in the server's clock.
  const when = parseApiWallClock(file.modified_at);
  const exact = when
    ? format.dateTime(when, { dateStyle: "medium", timeStyle: "medium", timeZone: "UTC" })
    : file.modified_at;
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {/* Not a tab stop (seven per row adds up); the exact time still reaches screen
            readers and the tooltip opens on hover. */}
        <span className="text-muted-foreground">
          {file.modified_at_human ?? file.modified_at}
          <span className="sr-only"> ({exact})</span>
        </span>
      </TooltipTrigger>
      <TooltipContent>{exact}</TooltipContent>
    </Tooltip>
  );
}

// Always `user:group`, even when equal, so the column means the same on every row.
function OwnerCell({ row }) {
  const file = row.original;

  // Null for a symlink, whose ownership is not meaningful (its mode is omitted too).
  if (!file.owner) return <span className="text-muted-foreground">—</span>;

  // Stacked owner over group, like Permissions, so it needs only the longer name's
  // width; `truncate` with the full value on hover is the last resort.
  return (
    <span
      className="flex min-w-0 flex-col gap-0.5 font-mono text-xs text-muted-foreground"
      title={file.group ? `${file.owner}:${file.group}` : file.owner}
    >
      <span className="min-w-0 truncate">{file.owner}</span>
      {file.group ? <span className="min-w-0 truncate text-muted-foreground/70">{file.group}</span> : null}
    </span>
  );
}

function PermissionsCell({ row }) {
  const t = useTranslations("applications.files");
  const sentenceFor = useModeSentence();
  const file = row.original;
  if (!file.mode) return <span className="text-muted-foreground">—</span>;
  const worldWritable = isWorldWritable(file.mode);
  const symbolic = symbolicMode(file.mode, file.type);
  // The picker's plain-language sentence, on hover.
  const sentence = sentenceFor(file.mode);
  return (
    // Both notations stacked: one line overflowed this column at 1024-1280px.
    // One tooltip for the whole cell, plus the world-writable warning when it applies.
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="flex flex-col gap-0.5 whitespace-nowrap font-mono text-xs w-fit cursor-help rounded text-muted-foreground">
          <span className="flex items-center gap-1.5">
            <span className={worldWritable ? "font-medium text-destructive" : undefined}>
              {symbolic ?? file.mode}
            </span>
            {worldWritable ? <TriangleAlert className="size-3.5 shrink-0 text-destructive" aria-hidden /> : null}
          </span>
          {/* Omitted when the mode has no symbolic form; the line above is already octal. */}
          {symbolic ? <span className="text-muted-foreground/70">{file.mode}</span> : null}
          {/* The tooltip's sentence for screen readers, since the cell is not a tab stop
              (see ModifiedCell). */}
          {sentence ? <span className="sr-only">{sentence}</span> : null}
        </span>
      </TooltipTrigger>
      <TooltipContent className="max-w-64">
        {sentence ?? file.mode}
        {worldWritable ? <p className="mt-1 font-medium">{t("columns.worldWritableHint")}</p> : null}
      </TooltipContent>
    </Tooltip>
  );
}

function ActionsCell({ row, table }) {
  const { appId, canManage, onAction, busyPath } = table.options.meta;
  const file = row.original;
  const busy = busyPath === file.path;
  if (busy) {
    return (
      <span className="flex justify-end pr-2">
        <Loader2 className="size-4 animate-spin text-muted-foreground" />
      </span>
    );
  }
  return <FileRowActions file={file} appId={appId} canManage={canManage} onAction={onAction} />;
}

export function FilesTable({
  appId,
  path,
  data,
  canManage,
  onAction,
  busyPath,
  highlightPath,
  selected = [],
  onToggle,
  onToggleAll,
  folderSizes = {},
  sizingPaths = [],
  initialSort = [{ id: "name", desc: false }],
}) {
  const t = useTranslations("applications.files");

  // Percentages with `fixedLayout`, so widths are bounded. The checkbox is a fixed
  // 48px, so shares sum to 92-93; a hidden column's share is not redistributed.
  const columns = [
    {
      id: "select",
      header: SelectAllHeader,
      meta: { className: "w-12 pl-6 pr-0" },
      cell: SelectCell,
      enableSorting: false,
    },
    {
      accessorKey: "name",
      header: t("columns.name"),
      meta: { className: "w-[32%] px-4 xl:w-[29%]" },
      cell: NameCell,
      sortingFn: sortByName,
    },
    {
      accessorKey: "size",
      header: () => <span className="block text-right">{t("columns.size")}</span>,
      meta: { className: "text-right w-[14%] whitespace-nowrap px-3 xl:w-[10%]" },
      cell: SizeCell,
      sortingFn: sortBySize,
    },
    {
      accessorKey: "modified_at",
      header: t("columns.modified"),
      meta: { className: "w-[16%] px-4 xl:w-[13%]" },
      cell: ModifiedCell,
      sortingFn: sortByModified,
    },
    {
      // Not sortable: sort would use `owner` alone while the cell shows `owner:group`.
      accessorKey: "owner",
      header: t("columns.owner"),
      meta: { className: "hidden w-[14%] px-4 whitespace-normal hyphens-auto xl:table-cell" },
      cell: OwnerCell,
      enableSorting: false,
    },
    {
      id: "permissions",
      header: t("columns.permissions"),
      meta: { className: "w-[16%] px-4 whitespace-normal hyphens-auto xl:w-[14%]" },
      cell: PermissionsCell,
      enableSorting: false,
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      meta: { className: "w-[14%] pr-6 pl-4 xl:w-[13%]" },
      cell: ActionsCell,
      enableSorting: false,
    },
  ];

  return (
    <DataTable
      columns={columns}
      data={data}
      meta={{
        appId,
        path,
        canManage,
        onAction,
        busyPath,
        selected,
        onToggle,
        onToggleAll,
        folderSizes,
        sizingPaths,
      }}
      emptyMessage={t("empty.title")}
      rowClassName={(file) =>
        cn(
          file.type === "symlink" && "opacity-70",
          "transition-colors duration-700",
          file.path === highlightPath && "bg-primary/10",
        )
      }
      sortable
      defaultSorting={initialSort}
      onSortingChange={(sorting) => writePref(SORT_COOKIE, serializeSort(sorting))}
      fixedLayout
      contextMenu={(file) => (
        <FileActionItems
          file={file}
          appId={appId}
          canManage={canManage}
          onAction={onAction}
          Item={ContextMenuItem}
          Separator={ContextMenuSeparator}
        />
      )}
    />
  );
}
