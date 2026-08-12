"use client";

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
  const { appId, path, onAction } = table.options.meta;

  if (file.type === "dir") {
    const href = `/applications/${appId}/files?path=${encodeURIComponent(file.path)}`;
    return (
      <Link
        href={href}
        className="flex min-w-0 items-center gap-2 rounded font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        <Folder className="size-4 shrink-0 text-primary" />
        <span className="truncate">{file.name}</span>
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
            <span className="truncate">{file.name}</span>
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

  return (
    <button
      type="button"
      onClick={() => onAction(isImageFile(file.name) ? "preview" : "edit", file)}
      className="flex min-w-0 items-center gap-2 rounded text-left font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      <FileThumb file={file} appId={appId} className="size-5" />
      <span className="truncate">{file.name}</span>
    </button>
  );
}

function SizeCell({ row, table }) {
  const file = row.original;
  const { folderSizes = {}, sizingPath } = table.options.meta;

  // A folder has no size until someone asks: the backend walks the tree to
  // work one out, so the listing does not carry it and the dash is honest
  // rather than a gap.
  if (sizingPath === file.path) {
    return <Loader2 className="ml-auto size-3.5 animate-spin text-muted-foreground" />;
  }

  const measured = folderSizes[file.path];
  return (
    <span className="tabular-nums text-muted-foreground">
      {measured ?? file.size_human ?? "—"}
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
 * Owner, and the group when it differs.
 *
 * Both are shown by `ls -l`, but a site's files are overwhelmingly
 * `owner:owner` — repeating the same name twice on every row is noise that
 * makes the rows where it *does* differ (`deploy:www-data`, the ones worth
 * noticing) harder to spot rather than easier.
 */
function OwnerCell({ row }) {
  const file = row.original;

  // Null for a symlink, whose own ownership is not a meaningful thing to show
  // — the same reason its mode is omitted.
  if (!file.owner) return <span className="text-muted-foreground">—</span>;

  return (
    <span className="flex items-center font-mono text-xs text-muted-foreground">
      <span className="truncate">{file.owner}</span>
      {file.group && file.group !== file.owner ? (
        <>
          <span className="text-muted-foreground/50">:</span>
          <span className="truncate">{file.group}</span>
        </>
      ) : null}
    </span>
  );
}

function PermissionsCell({ row }) {
  const t = useTranslations("applications.files");
  const file = row.original;
  if (!file.mode) return <span className="text-muted-foreground">—</span>;
  const worldWritable = isWorldWritable(file.mode);
  return (
    <span className="flex items-center gap-1.5 font-mono text-xs text-muted-foreground">
      {/* Symbolic, with the octal kept on hover: `drwxr-xr-x` is what anyone
          reads at a glance, but `755` is what the chmod dialog and every
          how-to guide talk in, so throwing it away would cost more than it
          saves. */}
      <span
        className={worldWritable ? "font-medium text-destructive" : undefined}
        title={file.mode}
      >
        {symbolicMode(file.mode, file.type) ?? file.mode}
      </span>
      {worldWritable ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <span tabIndex={0}>
              <TriangleAlert className="size-3.5 shrink-0 text-destructive" />
            </span>
          </TooltipTrigger>
          <TooltipContent className="max-w-60">{t("columns.worldWritableHint")}</TooltipContent>
        </Tooltip>
      ) : null}
    </span>
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
  // handing Name whatever's left over. Name and Permissions were each wider
  // than their real content (a filename, or "demo · 777") ever uses, so both
  // shrank here in favor of Size/Modified/Actions.
  //
  // Every column also gets the same `px-6` (the base cell's default is
  // `px-4`) — a column's percentage width only controls where its own text
  // sits within itself, not the gap to its neighbor, since two adjacent
  // cells' padding is what actually separates their text. Sizing every
  // column's padding identically is what makes every boundary read as the
  // same gap instead of some feeling tighter than others.
  const columns = [
    {
      id: "select",
      header: SelectAllHeader,
      meta: { className: "w-[4%] pl-6 pr-0" },
      cell: SelectCell,
      enableSorting: false,
    },
    {
      accessorKey: "name",
      header: t("columns.name"),
      meta: { className: "w-[22%] px-6" },
      cell: NameCell,
      sortingFn: sortByName,
    },
    {
      accessorKey: "size",
      header: () => <span className="block text-right">{t("columns.size")}</span>,
      meta: { className: "text-right w-[10%] px-6" },
      cell: SizeCell,
      sortingFn: sortBySize,
    },
    {
      accessorKey: "modified_at",
      header: t("columns.modified"),
      meta: { className: "w-[18%] px-6" },
      cell: ModifiedCell,
      sortingFn: sortByModified,
    },
    {
      // Sortable, unlike permissions: "show me everything root ended up
      // owning" is a real question after a restore or an install, and it is
      // the one this column exists to answer.
      accessorKey: "owner",
      header: t("columns.owner"),
      meta: { className: "w-[13%] px-6" },
      cell: OwnerCell,
    },
    {
      id: "permissions",
      header: t("columns.permissions"),
      meta: { className: "w-[13%] px-6" },
      cell: PermissionsCell,
      enableSorting: false,
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      meta: { className: "w-[20%] px-6" },
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
