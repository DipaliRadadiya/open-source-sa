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
  const roleById = new Map(roleOptions.map((r) => [r.id, r]));
  const usersWithRoles = users.map((u) => ({
    ...u,
    roles: (u.roles ?? []).map((r) => ({ ...r, ...(roleById.get(r.id) ?? {}) })),
  }));

  const hasFilters = Boolean(sp.search || sp.is_admin);

  if (failed) {
    return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;
  }

  redirectOutOfRange("/admin/users", sp, meta, failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />

      <UsersView roles={roleOptions} rolesFailed={rolesFailed}>
        <ListCard from="c900" toolbar={<UsersToolbar />} footer={<DataTablePagination meta={meta} />}>
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
