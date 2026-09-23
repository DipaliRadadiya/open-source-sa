import { useTranslations } from "next-intl";
import Link from "next/link";
import { Folder, Link2, Loader2, TriangleAlert, Unlink } from "lucide-react";
import { cn } from "@/lib/utils";
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

// Cells are module-level so flexRender's identity stays stable across
// re-renders — see the same note in workers-table.jsx.

// "DD-MM-YYYY HH:mm:ss" (the format every date on this API comes in) isn't
// chronologically sortable as a plain string — parse it into a comparable
// number, falling back to 0 (ties, sorts with whatever else is unparseable)
// rather than throwing on an unexpected shape.
function parseApiDate(value) {
  const m = /^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})$/.exec(value ?? "");
  if (!m) return 0;
  const [, dd, mm, yyyy, hh, min, ss] = m;
  return new Date(+yyyy, +mm - 1, +dd, +hh, +min, +ss).getTime();
}

// Folders always sort above files regardless of which column is active or
// which direction it's sorted — the same "browse structure first" convention
// every file manager uses. Each column supplies its own tiebreaker for rows
// of the same type.
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

/**
 * Selection lives in the panel, not in TanStack's own row-selection state: the
 * panel is what runs the bulk calls, and a selection keyed by row index would
 * point at the wrong file the moment the list re-sorts or refreshes. Paths are
 * the stable identity here.
 */
function SelectCell({ row, table }) {
  const t = useTranslations("applications.files");
  const { selected, onToggle } = table.options.meta;
  const file = row.original;
  return (
    <Checkbox
      checked={selected.includes(file.path)}
      onCheckedChange={() => onToggle(file.path)}
      aria-label={t("bulk.selectOne", { name: file.name })}
      // The row is a navigation target for folders — a tick must not follow
      // the link it happens to sit inside.
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
      // "This folder", never "everything on the site" — a select-all that
      // silently reaches past what you can see is how bulk deletes go wrong.
      checked={all ? true : some ? "indeterminate" : false}
      onCheckedChange={() => onToggleAll(rows, !all)}
      aria-label={t("bulk.selectAll")}
    />
  );
}

function NameCell({ row, table }) {
  const t = useTranslations("applications.files");
  const file = row.original;
  const { appId, onAction } = table.options.meta;

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
            {/* Where it points, inline rather than only on hover: a link is
                the one row whose name tells you nothing about what it is, and
                a dangling one is otherwise indistinguishable from a working
                one. */}
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

  // An archive or a binary has nowhere to go: the editor answers "this file
  // isn't text" and the preview cannot decode it. Offering the click and then
  // refusing it is a worse answer than not offering it — download and extract
  // are still on the row's menu.
  if (!canOpenFile(file.name)) {
    return (
      <span className="flex w-full min-w-0 items-center gap-2 font-medium">
        <FileThumb file={file} appId={appId} className="size-5" />
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
      // `w-full` for the same reason as the card view: a <button> is a form
      // control and sizes to its own content even as a flex box, so the inner
      // `truncate` measured against the name's full width rather than the
      // cell's. A long name ran past the column and into Size instead of
      // ellipsing. Folders never showed it — they render as an <a>, which does
      // fill the cell.
      className="flex w-full min-w-0 items-center gap-2 rounded text-left font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      <FileThumb file={file} appId={appId} className="size-5" />
      <span className={FILE_NAME} title={file.name}>
        {file.name}
      </span>
    </button>
  );
}

function SizeCell({ row, table }) {
  const file = row.original;
  const t = useTranslations("applications.files");
  const { folderSizes = {}, sizingPath, onAction } = table.options.meta;

  // A folder has no size until someone asks: the backend walks the tree to
  // work one out, so the listing does not carry it and the dash is honest
  // rather than a gap.
  if (sizingPath === file.path) {
    return <Loader2 className="ml-auto size-3.5 animate-spin text-muted-foreground" />;
  }

  // A folder shows only a MEASURED size. The listing's `size_human` for a
  // directory is the 4 KB of the directory entry itself — every folder read
  // "4.0 KB" while the Storage panel put the same tree at 100 MB. "Folder
  // size" on the row's menu measures the real one and it lands here.
  const shown = file.type === "dir" ? folderSizes[file.path] : file.size_human;
  // Asked for where the answer will appear. It was only in the ⋯ menu, which
  // is where nobody looks for a number that has its own column.
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
  const file = row.original;
  if (!file.modified_at) return <span className="text-muted-foreground">—</span>;
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span tabIndex={0} className="text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
          {file.modified_at_human ?? file.modified_at}
        </span>
      </TooltipTrigger>
      <TooltipContent>{file.modified_at}</TooltipContent>
    </Tooltip>
  );
}

/**
 * User and group, always both — `deploy:www-data`, the way `ls -l` reports it.
 *
 * Shown even when they are the same. Hiding the repeat would be tidier, but it
 * makes the column's meaning depend on its value: a reader seeing one name
 * cannot tell whether the group matches or simply was not rendered, and
 * "which of these is group-owned by the web server?" stops being answerable by
 * scanning.
 */
function OwnerCell({ row }) {
  const file = row.original;

  // Null for a symlink, whose own ownership is not a meaningful thing to show
  // — the same reason its mode is omitted.
  if (!file.owner) return <span className="text-muted-foreground">—</span>;

  // Stacked, owner over group — the same shape as Permissions beside it.
  // On one line `my-blog-ngkx:my-blog-ngkx` needs ~180px and was cut to
  // "my-blo…:my-blo…", which answers nothing; stacked it needs the width of
  // the longer name alone. `truncate` stays as the last resort for a truly
  // long account name, with the whole value on hover.
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
  // "Owner: read and write. Everyone else: read." — the same sentence the
  // permission picker and Fix permissions use, on hover, the way Modified
  // keeps its exact date. `-rw-r--r--` is exact but has to be decoded.
  const sentence = sentenceFor(file.mode);
  return (
    /*
     * Both notations, stacked, because they are one value.
     *
     * `drwxr-xr-x` is what anyone reads at a glance and shows WHICH of
     * read/write/execute is missing; `755` is what the Permissions dialog,
     * chmod and every how-to guide speak in. The octal was on hover only, so
     * the column and the dialog looked like two different readings of the same
     * file — reported as an inconsistency, and a fair reading of it.
     *
     * Stacked rather than side by side: measured with the sidebar in place,
     * one line overflowed this column by 40px at 1024, 21px at 1152 and 2px at
     * 1280, and the table only renders at all from 1024 up. Two lines need the
     * width of the longer string alone, which already fit.
     */
    // One tooltip for the whole cell: the sentence, plus the world-writable
    // warning when it applies — which used to be a second, icon-only tooltip.
    <Tooltip>
      <TooltipTrigger asChild>
        <span
          tabIndex={0}
          className="flex flex-col gap-0.5 whitespace-nowrap font-mono text-xs w-fit cursor-help rounded text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          <span className="flex items-center gap-1.5">
            <span className={worldWritable ? "font-medium text-destructive" : undefined}>
              {symbolic ?? file.mode}
            </span>
            {worldWritable ? <TriangleAlert className="size-3.5 shrink-0 text-destructive" aria-hidden /> : null}
          </span>
          {/* Omitted when the mode could not be read symbolically — the line
              above is then already the octal, and repeating it says nothing. */}
          {symbolic ? <span className="text-muted-foreground/70">{file.mode}</span> : null}
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
  sizingPath = null,
}) {
  const t = useTranslations("applications.files");

  // Percentages, not px, and they sum to 100 — paired with `fixedLayout`
  // below so the table stays full width but Name's share of it is actually
  // bounded, instead of `auto` layout treating a width as a hint and still
  // handing Name whatever's left over.
  //
  // Rebalanced for the width the table actually has. The last split was tuned
  // for a Storage side-rail that took 340px off it; Storage is a sheet now, so
  // the table has its full width back and was still dividing it as if it
  // didn't. With `px-6` on all seven columns, a third of the row was padding,
  // and Name — the one column whose content has no ceiling — got 22% of the
  // rest: every name in wp-admin/images was cut at "about-header-cre…".
  //
  // Name now takes a third and wraps to two lines (`FILE_NAME`). Inner cells
  // use the base cell's `px-4`, the same gap at every boundary; only the two
  // outer edges keep 24px to line up with the card. Owner is the column
  // people consult least, so it gives way below `xl` rather than squeezing
  // the others — the same trade the applications table makes.
  //
  // The checkbox column is a fixed 48px, not a share: its content is a 16px box
  // behind a 24px edge at every width, and as a percentage it came out 28px at
  // 1024 and spilled into Name. The shares below therefore sum to 92–93, leaving
  // room for it on the narrowest table (704px) instead of overflowing it —
  // once without Owner (below `xl`) and once with it, because a hidden
  // column's share is not handed back to the others.
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
      // Not sortable, like permissions. The argument for it was "show me
      // everything root ended up owning", and the sort does not answer that:
      // it orders by `owner` alone while the column shows `owner:group`, so
      // the halves of one value sort by half of it, and folders are pinned
      // above files first regardless — which scatters any owner that appears
      // in both. A control that reorders the list without answering the
      // question it was added for is worse than no control, because its
      // presence claims otherwise. Filtering is what that question wants, and
      // the search box already narrows on the text.
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
        sizingPath,
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
      defaultSorting={[{ id: "name", desc: false }]}
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
