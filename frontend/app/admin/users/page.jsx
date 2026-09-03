import { getTranslations } from "next-intl/server";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getUsers } from "@/lib/users/get-users";
import { getRoles } from "@/lib/roles/get-roles";
import { UsersView } from "@/components/admin/users/users-view";
import { UsersToolbar } from "@/components/admin/users/users-toolbar";
import { UsersTable } from "@/components/admin/users/users-table";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function AdminUsersPage({ searchParams }) {
  const sp = await searchParams;
  const [user, { users, meta, failed, status, failure }, { roles, failed: rolesFailed }, t] = await Promise.all([
    getCurrentUser(),
    getUsers(sp),
    getRoles(),
    getTranslations("users"),
  ]);

  // The roles picker needs id + name (+ description when present).
  const roleOptions = roles.map((r) => ({
    id: r.id,
    name: r.name,
    description: r.description,
  }));

  const hasFilters = Boolean(sp.search || sp.is_admin);


  // Before the redirect below, which reads `meta`: a failed load answers with
  // an empty page-1 meta, so bouncing on it would send the reader to page 1 to
  // read the same error. `failed` is passed on for the same reason.
  if (failed) {
    // status + failure let the panel name the cause — a 403 is the reader's
    // situation, a 500 is ours. The description is the fallback for the
    // failures it has no specific words for.
    return <LoadFailed description={t("loadFailed")} status={status} failure={failure} />;
  }

  // Before anything renders: a page past the end sends the reader to the
  // last real page instead of painting an error for it.
  redirectOutOfRange("/admin/users", sp, meta, failed);
  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      <UsersView roles={roleOptions} rolesFailed={rolesFailed}>
        <UsersToolbar />
        <UsersTable
          data={users}
          roles={roleOptions}
          rolesFailed={rolesFailed}
          currentUserId={user?.id}
          hasFilters={hasFilters}
        />
        {/* Not behind a row count: the selector hides itself when the list is too
            short to paginate, and gating it on the current page as well is how it
            used to vanish on the very page you needed it. */}
        <DataTablePagination meta={meta} />
      </UsersView>
    </div>
  );
}
