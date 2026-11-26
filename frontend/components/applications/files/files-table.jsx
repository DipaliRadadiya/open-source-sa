import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import Link from "@/components/ui/app-link";
import { Folder, Link2, Loader2, TriangleAlert, Unlink } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
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
import { measuredSize, sizeSortKey } from "@/lib/files/folder-sizes";
import { FileSize } from "@/components/applications/files/file-size";
import { HoverHint } from "@/components/ui/hover-hint";

function parseApiDate(value) {
  const m = /^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})$/.exec(value ?? "");
  if (!m) return 0;
  const [, dd, mm, yyyy, hh, min, ss] = m;
  return new Date(+yyyy, +mm - 1, +dd, +hh, +min, +ss).getTime();
}

function withDirsFirst(compare, desc = false) {
  return (rowA, rowB) => {
    const aDir = rowA.original.type === "dir";
    const bDir = rowB.original.type === "dir";
    if (aDir !== bDir) return (aDir ? -1 : 1) * (desc ? -1 : 1);
    return compare(rowA.original, rowB.original);
  };
}

const byName = (a, b) => a.name.localeCompare(b.name);
const byModified = (a, b) => parseApiDate(a.modified_at) - parseApiDate(b.modified_at);

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
        prefetch={false}
        className="flex min-w-0 items-center gap-2 rounded font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        <Folder className="size-4 shrink-0 text-primary" />
        <HoverHint content={file.name}><span className={FILE_NAME}>
          {file.name}
        </span></HoverHint>
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
            <HoverHint content={file.name}><span className={FILE_NAME}>
              {file.name}
            </span></HoverHint>
            {file.link_target ? (
              <span className="truncate font-mono text-xs text-muted-foreground">
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

  if (!canManage || !canOpenFile(file.name)) {
    return (
      <span className="flex w-full min-w-0 items-center gap-2 font-medium">
        <FileThumb file={file} appId={appId} className="size-5" canPreview={canManage} />
        <HoverHint content={file.name}><span className={FILE_NAME}>
          {file.name}
        </span></HoverHint>
      </span>
    );
  }

  return (
    <button
      type="button"
      onClick={() => onAction(isImageFile(file.name) ? "preview" : "edit", file)}
      className="flex w-full min-w-0 items-center gap-2 rounded text-left font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      <FileThumb file={file} appId={appId} className="size-5" canPreview={canManage} />
      <HoverHint content={file.name}><span className={FILE_NAME}>
        {file.name}
      </span></HoverHint>
    </button>
  );
}

function SizeCell({ row, table }) {
  const file = row.original;
  const t = useTranslations("applications.files");
  const { folderSizes } = table.options.meta;

  if (file.type !== "dir") {
    return file.size == null ? (
      <span className="text-muted-foreground">—</span>
    ) : (
      <FileSize bytes={file.size} className="tabular-nums text-muted-foreground" />
    );
  }
  const measured = measuredSize(file, folderSizes);
  if (!measured) {
    return folderSizes?.loading ? (
      <Skeleton className="ml-auto h-3.5 w-12" />
    ) : (
      <HoverHint content={t("sizes.notMeasured")}><span className="text-muted-foreground">—</span></HoverHint>
    );
  }
  return <FileSize bytes={measured.size} className="tabular-nums text-muted-foreground" />;
}

function ModifiedCell({ row }) {
  const format = useFormatter();
  const file = row.original;
  if (!file.modified_at) return <span className="text-muted-foreground">—</span>;
  const when = parseApiWallClock(file.modified_at);
  const exact = when
    ? format.dateTime(when, { dateStyle: "medium", timeStyle: "medium", timeZone: "UTC" })
    : file.modified_at;
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="text-muted-foreground">
          {file.modified_at_human ?? file.modified_at}
          <span className="sr-only"> ({exact})</span>
        </span>
      </TooltipTrigger>
      <TooltipContent>{exact}</TooltipContent>
    </Tooltip>
  );
}

function OwnerCell({ row }) {
  const file = row.original;

  if (!file.owner) return <span className="text-muted-foreground">—</span>;

  return (
    <HoverHint content={file.group ? `${file.owner}:${file.group}` : file.owner}><span
      className="flex min-w-0 flex-col gap-0.5 font-mono text-xs text-muted-foreground"
    >
      <span className="min-w-0 truncate">{file.owner}</span>
      {file.group ? <span className="min-w-0 truncate">{file.group}</span> : null}
    </span></HoverHint>
  );
}

function PermissionsCell({ row }) {
  const t = useTranslations("applications.files");
  const sentenceFor = useModeSentence();
  const file = row.original;
  if (!file.mode) return <span className="text-muted-foreground">—</span>;
  const worldWritable = isWorldWritable(file.mode);
  const symbolic = symbolicMode(file.mode, file.type);
  const sentence = sentenceFor(file.mode);
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="flex flex-col gap-0.5 whitespace-nowrap font-mono text-xs w-fit cursor-help rounded text-muted-foreground">
          <span className="flex items-center gap-1.5">
            <span className={worldWritable ? "font-medium text-destructive" : undefined}>
              {symbolic ?? file.mode}
            </span>
            {worldWritable ? <TriangleAlert className="size-3.5 shrink-0 text-destructive" aria-hidden /> : null}
          </span>
          {symbolic ? <span>{file.mode}</span> : null}
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
  folderSizes = null,
  initialSort = [{ id: "name", desc: false }],
}) {
  const t = useTranslations("applications.files");
  const [sorting, setSorting] = useState(initialSort);
  const isDesc = (id) => sorting.some((s) => s.id === id && s.desc);

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
      sortingFn: withDirsFirst(byName, isDesc("name")),
    },
    {
      accessorKey: "size",
      header: () => <span className="block text-right">{t("columns.size")}</span>,
      meta: { className: "text-right w-[14%] whitespace-nowrap px-3 xl:w-[10%]" },
      cell: SizeCell,
      sortingFn: withDirsFirst((a, b) => sizeSortKey(a, folderSizes) - sizeSortKey(b, folderSizes), isDesc("size")),
      sortDescFirst: true,
    },
    {
      accessorKey: "modified_at",
      header: t("columns.modified"),
      meta: { className: "w-[16%] px-4 xl:w-[13%]" },
      cell: ModifiedCell,
      sortingFn: withDirsFirst(byModified, isDesc("modified_at")),
    },
    {
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
      columns={canManage ? columns : columns.filter((column) => column.id !== "select")}
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
      onSortingChange={(next) => {
        setSorting(next);
        writePref(SORT_COOKIE, serializeSort(next));
      }}
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
