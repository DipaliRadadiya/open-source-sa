"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { SearchX, Server, Plus, Users, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { SearchInput } from "@/components/data-table/search-input";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { ListCard } from "@/components/data-table/list-card";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { useSetQuery } from "@/hooks/use-set-query";
import { useSearchParams } from "next/navigation";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { AccessSwitch } from "@/components/system-users/access-switch";
import { ShellSelect } from "@/components/system-users/shell-select";
import { AppsCell } from "@/components/system-users/apps-cell";
import { PasswordReveal } from "@/components/system-users/password-reveal";
import { CopyButton } from "@/components/ui/copy-button";
import { SystemUserRowActions } from "@/components/system-users/system-user-row-actions";
import { CreateSystemUserDialog } from "@/components/system-users/create-system-user-dialog";
import { SystemUsersCards } from "@/components/system-users/system-users-cards";
import { BulkDeleteDialog } from "@/components/system-users/bulk-delete-dialog";
import { BulkFailure } from "@/components/system-users/bulk-failure";
import { Checkbox } from "@/components/ui/checkbox";
import { BULK_FIXES } from "@/lib/system-users/bulk-delete";

// Cells at module level: inline cells remount on every keystroke.

function Head({ children }) {
  return <span className="block whitespace-nowrap">{children}</span>;
}

function UsernameCell({ row, table }) {
  const t = useTranslations("systemUsers");
  const showBadge = !table.options.meta.canManage && !(row.original.password_known ?? row.original.password);

  return (
    <div className="min-w-0 max-w-56 space-y-0.5 whitespace-normal">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="font-medium [overflow-wrap:anywhere]">{row.original.username}</span>
        {showBadge ? (
          <Badge variant="warning" className="font-normal">
            {t("noPassword")}
          </Badge>
        ) : null}
      </div>
      <p className="flex items-start gap-1 font-mono text-xs text-muted-foreground">
        <span className="min-w-0 pt-1 [overflow-wrap:anywhere]">{row.original.home_path}</span>
        <CopyButton value={row.original.home_path} label={t("detail.copyPath")} className="size-6" />
      </p>
      <BulkFailure failure={table.options.meta.failures?.[row.original.id]} />
    </div>
  );
}

function SelectHeaderCell({ table }) {
  const t = useTranslations("systemUsers.bulk");
  const all = table.getIsAllRowsSelected();
  return (
    <Checkbox
      checked={all || (table.getIsSomeRowsSelected() ? "indeterminate" : false)}
      onCheckedChange={(value) => table.toggleAllRowsSelected(!!value)}
      aria-label={t("selectAll")}
    />
  );
}

function SelectCell({ row }) {
  const t = useTranslations("systemUsers.bulk");
  return (
    <Checkbox
      checked={row.getIsSelected()}
      onCheckedChange={(value) => row.toggleSelected(!!value)}
      aria-label={t("selectRow", { username: row.original.username })}
    />
  );
}

function PasswordCell({ row }) {
  return (
    <div className="w-36">
      <PasswordReveal password={row.original.password} />
    </div>
  );
}

function ShellCell({ row, table }) {
  return (
    <ShellSelect
      user={row.original}
      shells={table.options.meta.shells}
      canManage={table.options.meta.canManage}
      className="min-h-8 w-44 py-1 text-left leading-snug whitespace-normal data-[size=default]:h-auto *:data-[slot=select-value]:line-clamp-2"
    />
  );
}

function SudoCell({ row, table }) {
  return (
    <AccessSwitch user={row.original} field="sudo" canManage={table.options.meta.canManage} />
  );
}

function SshCell({ row, table }) {
  return (
    <AccessSwitch
      user={row.original}
      field="ssh"
      canManage={table.options.meta.canManage}
      sshEnforced={table.options.meta.sshEnforced}
    />
  );
}

function ApplicationsCell({ row }) {
  return <AppsCell user={row.original} />;
}

function CreatedCell({ row }) {
  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {row.original.created_at_human}
    </span>
  );
}

function RowActionsCell({ row, table }) {
  const { canManage, prevPage, sshPasswordOff, canOpenSecurity } = table.options.meta;
  return (
    <SystemUserRowActions
      user={row.original}
      canManage={canManage}
      prevPage={prevPage}
      sshPasswordOff={sshPasswordOff}
      canOpenSecurity={canOpenSecurity}
    />
  );
}

export function SystemUsersTable(props) {
  return (
    <NavTransitionProvider>
      <SystemUsersList {...props} />
    </NavTransitionProvider>
  );
}

function SystemUsersList({
  data,
  meta,
  shells = [],
  canManage = false,
  canOpenSecurity = false,
  sshPasswordOff = false,
}) {
  const t = useTranslations("systemUsers");
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const [createOpen, setCreateOpen] = useState(false);
  // Keyed by id so a reorder can't change who gets deleted.
  const [selection, setSelection] = useState({});
  const [bulkOpen, setBulkOpen] = useState(false);
  const [bulkResult, setBulkResult] = useState(null);

  const filtered = data;
  const selected = filtered.filter((user) => selection[String(user.id)]);
  const failures = bulkResult?.failures ?? {};
  const keptCount = Object.keys(failures).length;

  function clearSelection() {
    setSelection({});
    setBulkResult(null);
  }

  const columns = [
    ...(canManage ? [{ id: "select", header: SelectHeaderCell, meta: { className: "w-10" }, cell: SelectCell }] : []),
    { accessorKey: "username", header: () => <Head>{t("columns.username")}</Head>, cell: UsernameCell },
    ...(canManage
      ? [{ id: "password", header: () => <Head>{t("columns.password")}</Head>, cell: PasswordCell }]
      : []),
    { accessorKey: "shell", header: () => <Head>{t("columns.shell")}</Head>, cell: ShellCell },
    { id: "sudo", header: () => <Head>{t("sudo")}</Head>, meta: { className: "px-2" }, cell: SudoCell },
    { id: "ssh", header: () => <Head>{t("ssh")}</Head>, meta: { className: "px-2" }, cell: SshCell },
    { id: "applications", header: () => <Head>{t("columns.applications")}</Head>, cell: ApplicationsCell },
    {
      accessorKey: "created_at_human",
      header: () => <Head>{t("columns.created")}</Head>,
      meta: { className: "hidden 2xl:table-cell" },
      cell: CreatedCell,
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      meta: { className: "px-2" },
      cell: RowActionsCell,
    },
  ];

  const prevPage = data.length === 1 && meta?.current_page > 1 ? meta.current_page - 1 : null;
  const sshEnforced = meta?.ssh_access_enforced ?? null;

  const isFiltered = Boolean(searchParams.get("search"));

  const toolbar = (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
      <SearchInput placeholder={t("searchPlaceholder")} />
      <div className="flex flex-wrap items-center gap-2">
        {canManage && selected.length > 0 ? (
          <>
            <span className="text-sm text-muted-foreground">{t("bulk.selected", { count: selected.length })}</span>
            <Button variant="ghost" onClick={clearSelection}>
              {t("bulk.clear")}
            </Button>
            <Button variant="destructive" onClick={() => setBulkOpen(true)}>
              <Trash2 className="size-4" />
              {t("bulk.action")}
            </Button>
          </>
        ) : null}
        <RefreshButton />
        <ReasonTooltip reason={canManage ? null : DENIED}>
          <Button disabled={!canManage} onClick={() => setCreateOpen(true)} data-su-add>
            <Plus className="size-4" />
            {t("addUser")}
          </Button>
        </ReasonTooltip>
      </div>
    </div>
  );

  return (
    <div className="space-y-4">
      {/* Until Access & security is saved, sshd has no AllowGroups line. null means not checked. */}
      {canManage && sshEnforced === false && data.length ? (
        <Caution
          size="md"
          action={
            canOpenSecurity ? (
              <Button asChild size="sm" variant="outline" className="h-8">
                <Link href="/settings/security" prefetch={false}>
                  {t("sshNotEnforced.action")}
                </Link>
              </Button>
            ) : null
          }
        >
          <p>{t("sshNotEnforced.body")}</p>
        </Caution>
      ) : null}

      {bulkResult && keptCount > 0 ? (
        <Caution size="md" tone="destructive">
          <p>{t("bulk.partial", { deleted: bulkResult.succeeded.length, kept: keptCount })}</p>
          {BULK_FIXES.filter((reason) => Object.values(failures).some((failure) => failure.reason === reason)).map((reason) => (
            <p key={reason}>{t(`bulk.fix.${reason}`)}</p>
          ))}
        </Caution>
      ) : null}

      {filtered.length === 0 ? (
        <ListCard toolbar={toolbar}>
          {isFiltered ? (
            <EmptyState
              icon={SearchX}
              subject={Users}
              title={t("empty.filteredTitle")}
              action={
                <Button
                  variant="outline"
                  onClick={() => {
                    setQuery({ search: undefined }, { resetPage: true });
                    document.querySelector("[data-search-input]")?.focus();
                  }}
                >
                  {t("clearSearch")}
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={Server}
              title={t("empty.title")}
              description={t("empty.desc")}
              action={
                <ReasonTooltip reason={canManage ? null : DENIED}>
                  <Button disabled={!canManage} onClick={() => setCreateOpen(true)}>
                    <Plus className="size-4" />
                    {t("addUser")}
                  </Button>
                </ReasonTooltip>
              }
            />
          )}
        </ListCard>
      ) : (
        <ListCard from="c900" toolbar={toolbar} footer={<DataTablePagination meta={meta} />}>
          <div className="@min-[900px]/page:hidden">
            <SystemUsersCards
              users={filtered}
              shells={shells}
              canManage={canManage}
              prevPage={prevPage}
              sshEnforced={sshEnforced}
              sshPasswordOff={sshPasswordOff}
              canOpenSecurity={canOpenSecurity}
              selection={canManage ? selection : null}
              onSelectionChange={setSelection}
              failures={failures}
            />
          </div>
          <div className="hidden @min-[900px]/page:block">
            <DataTable
              bare
              roomy
              columns={columns}
              data={filtered}
              meta={{ canManage, shells, prevPage, sshEnforced, sshPasswordOff, canOpenSecurity, failures }}
              {...(canManage
                ? { rowSelection: selection, onRowSelectionChange: setSelection, rowId: (user) => String(user.id) }
                : null)}
            />
          </div>
        </ListCard>
      )}

      {canManage ? (
        <BulkDeleteDialog
          users={selected}
          open={bulkOpen}
          onOpenChange={setBulkOpen}
          onDone={(outcome) => {
            setSelection(Object.fromEntries(Object.keys(outcome.failures).map((id) => [String(id), true])));
            setBulkResult(outcome);
          }}
        />
      ) : null}

      {canManage ? (
        <CreateSystemUserDialog open={createOpen} onOpenChange={setCreateOpen} initialShells={shells} />
      ) : null}
    </div>
  );
}
