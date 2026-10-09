import { getTranslations } from "next-intl/server";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getUsers } from "@/lib/users/get-users";
import { getRoles } from "@/lib/roles/get-roles";
import { UsersView } from "@/components/admin/users/users-view";
import { UsersToolbar } from "@/components/admin/users/users-toolbar";
import { UsersTable } from "@/components/admin/users/users-table";
import { ListCard } from "@/components/data-table/list-card";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

export default async function AdminUsersPage({ searchParams }) {
  const sp = await searchParams;
  const [user, { users, meta, failed, status, failure, message }, { roles, failed: rolesFailed }, t] = await Promise.all([
    getCurrentUser(),
    getUsers(sp),
    getRoles(),
    getTranslations("users"),
  ]);

  const roleOptions = roles.map((r) => ({
    id: r.id,
    name: r.name,
    description: r.description,
    slug: r.slug,
    is_system: r.is_system,
  }));
  // A user's roles arrive as {id, name}; the built-in flag comes from the roles list
  // so the Administrator role can be shown in the reader's language.
  const roleById = new Map(roleOptions.map((r) => [r.id, r]));
  const usersWithRoles = users.map((u) => ({
    ...u,
    roles: (u.roles ?? []).map((r) => ({ ...r, ...(roleById.get(r.id) ?? {}) })),
  }));

  const hasFilters = Boolean(sp.search || sp.is_admin);


  // Before the redirect below: a failed load returns an empty page-1 meta, so
  // redirecting on it would land on page 1 with the same error.
  if (failed) {
    return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;
  }

  // A page past the end redirects to the last real page.
  redirectOutOfRange("/admin/users", sp, meta, failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      <UsersView roles={roleOptions} rolesFailed={rolesFailed}>
        {/* Below lg the rows are cards of their own, so the list drops its frame there. The
            pager is not gated on row count: it hides itself when the list is too short. */}
        <ListCard from="lg" toolbar={<UsersToolbar />} footer={<DataTablePagination meta={meta} />}>
          <UsersTable
            data={usersWithRoles}
            roles={roleOptions}
            rolesFailed={rolesFailed}
            currentUserId={user?.id}
            hasFilters={hasFilters}
          />
        </ListCard>
      </UsersView>
    </div>
  );
}
