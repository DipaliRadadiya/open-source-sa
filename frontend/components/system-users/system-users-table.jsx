"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { SearchX, Server, Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { SearchInput } from "@/components/data-table/search-input";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { useSetQuery } from "@/hooks/use-set-query";
import { useSearchParams } from "next/navigation";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { AccessSwitch } from "@/components/system-users/access-switch";
import { ShellSelect } from "@/components/system-users/shell-select";
import { AppsCell } from "@/components/system-users/apps-cell";
import { PasswordReveal } from "@/components/system-users/password-reveal";
import { SystemUserRowActions } from "@/components/system-users/system-user-row-actions";
import { CreateSystemUserDialog } from "@/components/system-users/create-system-user-dialog";
import { SystemUsersCards } from "@/components/system-users/system-users-cards";

/* ---------------------------------------------------------------------------
 * Cells are module-level components on purpose: flexRender calls
 * `createElement(cellFn)`, so an inline cell gets a new identity each render
 * and every keystroke in the search box would remount every cell.
 *
 * `canManage` reaches them through `table.options.meta`.
 * ------------------------------------------------------------------------- */

// Headers may wrap: in French and Russian a one-line "NOM D'UTILISATEUR" pushed
// the row menu off the edge.
function Head({ children }) {
  return <span className="block whitespace-normal">{children}</span>;
}

function UsernameCell({ row, table }) {
  const t = useTranslations("systemUsers");
  // Managers see "Not set" in the password column; viewers have no column, so the
  // badge is their only signal that the account cannot be logged into.
  const showBadge = !table.options.meta.canManage && !(row.original.password_known ?? row.original.password);

  // Home sits under the name rather than in its own column, to save width.
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
      <p className="font-mono text-xs text-muted-foreground [overflow-wrap:anywhere]">
        {row.original.home_path}
      </p>
    </div>
  );
}

// Managers only: a viewer's row carries a redacted placeholder, not the password.
// Masked until asked for.
function PasswordCell({ row }) {
  return (
    <div className="w-44">
      <PasswordReveal password={row.original.password} />
    </div>
  );
}

function ShellCell({ row, table }) {
  return (
    // Fixed width, wrapping long titles: sized to its text, the Russian title pushed
    // the table past the edge; truncated, it lost its end.
    <ShellSelect
      user={row.original}
      shells={table.options.meta.shells}
      canManage={table.options.meta.canManage}
      className="min-h-8 w-48 py-1 text-left leading-snug whitespace-normal data-[size=default]:h-auto *:data-[slot=select-value]:line-clamp-2"
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
  const { canManage, prevPage } = table.options.meta;
  return <SystemUserRowActions user={row.original} canManage={canManage} prevPage={prevPage} />;
}

export function SystemUsersTable(props) {
  // Shared transition: search box spinner and table dim while the server answers.
  return (
    <NavTransitionProvider>
      <SystemUsersList {...props} />
    </NavTransitionProvider>
  );
}

function SystemUsersList({ data, meta, shells = [], canManage = false, canOpenSecurity = false }) {
  const t = useTranslations("systemUsers");
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const [createOpen, setCreateOpen] = useState(false);

  // Filtered and paged by the API; filtering here would only search this page.
  const filtered = data;

  const columns = [
    { accessorKey: "username", header: () => <Head>{t("columns.username")}</Head>, cell: UsernameCell },
    ...(canManage
      ? [{ id: "password", header: () => <Head>{t("columns.password")}</Head>, cell: PasswordCell }]
      : []),
    { accessorKey: "shell", header: () => <Head>{t("columns.shell")}</Head>, cell: ShellCell },
    // Tighter padding on the narrow control columns gives German and Russian the room
    // they need at 1280.
    { id: "sudo", header: () => <Head>{t("sudo")}</Head>, meta: { className: "px-3" }, cell: SudoCell },
    { id: "ssh", header: () => <Head>{t("ssh")}</Head>, meta: { className: "px-3" }, cell: SshCell },
    { id: "applications", header: () => <Head>{t("columns.applications")}</Head>, cell: ApplicationsCell },
    /*
     * Hidden below 2xl: at 1440 nine columns overflow and push Actions off screen.
     * Created is dropped because nothing is decided by it (same as the applications
     * table).
     *
     * Home stays, under the username: `home_path` comes from /etc/passwd via
     * SystemUserDiscoverer during Server Sync, so adopted accounts can live anywhere.
     *
     * Below 1280 the list is cards, so the table only has to fit from there.
     */
    {
      accessorKey: "created_at_human",
      header: () => <Head>{t("columns.created")}</Head>,
      meta: { className: "hidden 2xl:table-cell" },
      cell: CreatedCell,
    },
    // For viewers too: the menu is how SSH keys are reached.
    {
      id: "actions",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      meta: { className: "px-2" },
      cell: RowActionsCell,
    },
  ];

  // The page's only row: deleting it leaves the page, so go to the one before.
  const prevPage = data.length === 1 && meta?.current_page > 1 ? meta.current_page - 1 : null;
  const sshEnforced = meta?.ssh_access_enforced ?? null;

  const isFiltered = Boolean(searchParams.get("search"));

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <SearchInput placeholder={t("searchPlaceholder")} />
        <div className="flex flex-wrap items-center gap-2">
          <RefreshButton />
          <ReasonTooltip reason={canManage ? null : t("noPermission")}>
            <Button disabled={!canManage} onClick={() => setCreateOpen(true)} data-su-add>
              <Plus className="size-4" />
              {t("addUser")}
            </Button>
          </ReasonTooltip>
        </div>
      </div>

      {/* Above the rows, beside the switches it concerns: until Settings → Access &
          security is saved, sshd has no AllowGroups line, so "SSH login: off" keeps
          nobody out. Only `false`: `null` means sshd could not be asked. */}
      {/* Managers only: the fix is theirs; a viewer can neither change the switches nor
          save the setting. */}
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

      {filtered.length === 0 ? (
        isFiltered ? (
          <EmptyState
            icon={SearchX}
            title={t("empty.filteredTitle")}
            action={
              <Button
                variant="outline"
                onClick={() => {
                  setQuery({ search: undefined }, { resetPage: true });
                  // The button disappears with the empty state; focus the search box instead.
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
              // Even on the empty state: a disabled button explains why the user can't act.
              <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                <Button disabled={!canManage} onClick={() => setCreateOpen(true)}>
                  <Plus className="size-4" />
                  {t("addUser")}
                </Button>
              </ReasonTooltip>
            }
          />
        )
      ) : (
        <>
          {/* Cards below xl (1280), the table from there up, as in Cron Jobs: seven columns
              need ~950px, available at 1280 with the sidebar open. */}
          <div className="xl:hidden">
            <SystemUsersCards
              users={filtered}
              shells={shells}
              canManage={canManage}
              prevPage={prevPage}
              sshEnforced={sshEnforced}
            />
          </div>
          <div className="hidden xl:block">
            <DataTable
              columns={columns}
              data={filtered}
              meta={{ canManage, shells, prevPage, sshEnforced }}
            />
          </div>
        </>
      )}

      <DataTablePagination meta={meta} />

      {canManage ? (
        <CreateSystemUserDialog open={createOpen} onOpenChange={setCreateOpen} initialShells={shells} />
      ) : null}
    </div>
  );
}
