"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { ChevronRight, Database, Plus, SearchX, Trash2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { CreateDatabaseDialog } from "@/components/databases/create-database-dialog";
import { DeleteDatabaseDialog } from "@/components/databases/delete-database-dialog";

/* Cells are module-level components: flexRender treats a cell function's
 * identity as the component type, so inline definitions remount every cell on
 * each keystroke in the search box that lives in this same component. */

// The name is the way in: users, credentials and the connection string all
// live on the detail page, so the row has to lead somewhere.
function NameCell({ row }) {
  return (
    <Link
      href={`/databases/${row.original.id}`}
      // Primary colour, not plain text: nothing else said this name was the
      // way into the database. The chevron repeats it for anyone who does not
      // read colour as "clickable".
      className="group inline-flex items-center gap-1.5 font-mono font-medium text-primary underline-offset-4 hover:underline"
    >
      {row.original.name}
      <ChevronRight className="size-3.5 opacity-0 transition-opacity group-hover:opacity-100" />
    </Link>
  );
}

function EngineCell({ row, table }) {
  return (
    <span className="text-muted-foreground">
      {table.options.meta.engineName(row.original.engine)}
    </span>
  );
}

function SizeCell({ row }) {
  return (
    <span className="whitespace-nowrap tabular-nums">
      {row.original.size_human ?? "—"}
    </span>
  );
}

/**
 * Zero users is the state worth seeing: nothing can connect to that database,
 * so it is doing no work and nobody would otherwise notice.
 */
function UsersCell({ row }) {
  const t = useTranslations("databases");
  const count = row.original.users_count ?? 0;

  if (count === 0) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("columns.noUsers")}
      </Badge>
    );
  }
  return <span className="tabular-nums">{count}</span>;
}

/**
 * The question the list could not answer: is this database protected?
 *
 * A 2 GB production database nobody has ever dumped looked identical to one
 * backed up ten minutes ago. "Never" is the state worth the colour — and when
 * the exports request itself failed, this says nothing rather than lie.
 */
function BackupCell({ row, table }) {
  const t = useTranslations("databases");
  const { lastBackup, backupsUnknown } = table.options.meta;

  if (backupsUnknown) {
    return <span className="text-muted-foreground">—</span>;
  }

  const backup = lastBackup?.[row.original.id];
  if (!backup) {
    return (
      <Badge variant="warning" className="font-normal">
        {t("columns.neverBackedUp")}
      </Badge>
    );
  }

  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {backup.finished_at_human ?? backup.created_at_human ?? "—"}
    </span>
  );
}

function CreatedCell({ row }) {
  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {row.original.created_at_human ?? "—"}
    </span>
  );
}

function RowActionsCell({ row, table }) {
  return (
    <RowActions database={row.original} onDelete={table.options.meta.onDelete} />
  );
}

/**
 * Delete is the only row action, so it is the button — a menu that opens to
 * reveal one item costs a click and hides the only thing you can do. When a
 * second action arrives (export is the likely one) this goes back to a menu.
 */
function RowActions({ database, onDelete }) {
  const t = useTranslations("databases");
  const href = `/databases/${database.id}`;

  return (
    <div className="flex items-center justify-end gap-1">
      {/* Spelled out, not an icon. Users, credentials and backups all live on
          the detail page, and nothing on this row said so — people could not
          find them without being told the name was clickable. */}
      <Button asChild variant="outline" size="sm">
        <Link href={href}>
          {t("manage")}
          <ChevronRight className="size-3.5" />
        </Link>
      </Button>

      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            aria-label={t("delete.forName", { name: database.name })}
            className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
            onClick={() => onDelete(database)}
          >
            <Trash2 className="size-4" />
          </Button>
        </TooltipTrigger>
        <TooltipContent>{t("delete.action")}</TooltipContent>
      </Tooltip>
    </div>
  );
}

export function DatabasesTable({
  data,
  engines = [],
  canManage = false,
  lastBackup = {},
  backupsUnknown = false,
}) {
  const t = useTranslations("databases");
  const [query, setQuery] = useState("");
  const [createOpen, setCreateOpen] = useState(false);
  const [deleting, setDeleting] = useState(null);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return data;
    return data.filter((db) => db.name.toLowerCase().includes(q));
  }, [data, query]);

  // With one engine every row says the same word, and a column of one repeated
  // value is noise dressed as information.
  const engineNames = new Set(data.map((db) => db.engine));
  const showEngine = engineNames.size > 1;

  const columns = [
    { accessorKey: "name", header: t("columns.name"), cell: NameCell, sortingFn: "alphanumeric" },
    ...(showEngine
      ? [{ accessorKey: "engine", header: t("columns.engine"), cell: EngineCell }]
      : []),
    {
      accessorKey: "size_bytes",
      header: t("columns.size"),
      cell: SizeCell,
      // Sorts on the raw bytes, not the "148 MB" string — otherwise 9 KB
      // sorts above 148 MB.
      sortingFn: "basic",
    },
    {
      accessorKey: "users_count",
      header: t("columns.users"),
      cell: UsersCell,
      sortingFn: "basic",
    },
    {
      id: "lastBackup",
      // Sorts on the dump's timestamp, so "Never" (0) sinks to the bottom
      // ascending and rises to the top descending — the sort someone reaches
      // for this column to do.
      accessorFn: (row) => lastBackup?.[row.id]?.at ?? 0,
      header: t("columns.lastBackup"),
      cell: BackupCell,
      sortingFn: "basic",
    },
    {
      // Sorts by id: "2 months ago" is a sentence, and created_at is
      // DD-MM-YYYY, which sorts alphabetically into nonsense. Ids are issued in
      // creation order.
      id: "created",
      accessorFn: (row) => row.id,
      header: t("columns.created"),
      cell: CreatedCell,
      sortingFn: "basic",
    },
    ...(canManage
      ? [
          {
            id: "actions",
            enableSorting: false,
            header: () => <span className="sr-only">{t("actions")}</span>,
            // Same shape as the firewall table: last column, fixed width,
            // right-aligned, so every list in the panel puts its row actions in
            // the same place.
            meta: { className: "w-40 text-right" },
            cell: RowActionsCell,
          },
        ]
      : []),
  ];

  // No running engine means create would fail before it started.
  const usable = engines.filter((engine) => engine.running);
  const createReason = !canManage
    ? t("noPermission")
    : usable.length === 0
      ? t("create.noEngine")
      : null;

  const createButton = (
    <ReasonTooltip reason={createReason}>
      <Button disabled={Boolean(createReason)} onClick={() => setCreateOpen(true)}>
        <Plus className="size-4" />
        {t("create.action")}
      </Button>
    </ReasonTooltip>
  );

  const isFiltered = query.trim().length > 0;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <LocalSearchInput
          value={query}
          onChange={setQuery}
          placeholder={t("searchPlaceholder")}
        />
        <div className="flex items-center gap-2">
          <RefreshButton />
          {createButton}
        </div>
      </div>

      {filtered.length === 0 ? (
        isFiltered ? (
          <EmptyState
            icon={SearchX}
            title={t("empty.filteredTitle")}
            description={t("empty.filteredDesc")}
            action={
              <Button variant="outline" onClick={() => setQuery("")}>
                {t("empty.clearSearch")}
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Database}
            title={t("empty.title")}
            description={t("empty.description")}
            action={createButton}
          />
        )
      ) : (
        <DataTable
          columns={columns}
          data={filtered}
          sortable
          // Biggest first: the reason to sort by size is to find what is
          // filling the disk.
          defaultSorting={[{ id: "size_bytes", desc: true }]}
          meta={{
            canManage,
            onDelete: setDeleting,
            engineName: (engine) => t(`engines.${engine}`),
            lastBackup,
            backupsUnknown,
          }}
        />
      )}

      {canManage ? (
        <>
          <CreateDatabaseDialog
            engines={engines}
            open={createOpen}
            onOpenChange={setCreateOpen}
          />
          <DeleteDatabaseDialog
            database={deleting}
            open={deleting !== null}
            onOpenChange={(next) => !next && setDeleting(null)}
          />
        </>
      ) : null}
    </div>
  );
}
