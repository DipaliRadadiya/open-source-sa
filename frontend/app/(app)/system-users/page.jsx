import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSystemUsersPage } from "@/lib/system-users/get-system-users";
import { getShells } from "@/lib/system-users/get-shells";
import { SystemUsersTable } from "@/components/system-users/system-users-table";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

// Stands in for a password a viewer may not see; never rendered.
const REDACTED = "••••••••";

export default async function SystemUsersPage({ searchParams }) {
  const sp = await searchParams;
  const query = new URLSearchParams(
    Object.entries(sp ?? {}).filter(([, v]) => typeof v === "string"),
  ).toString();
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("systemUsers"),
  ]);

  if (!can(permissions, "system_user", "view")) return <PermissionDenied title={t("title")} />;
  // Shells come from the server so the picker never offers one it refuses.
  const [usersPage, shells] = await Promise.all([getSystemUsersPage(query), getShells()]);
  const canManage = can(permissions, "system_user", "manage");

  // SECURITY: the index endpoint returns cleartext passwords. Viewers get a
  // placeholder so they never reach the client payload. It must stay truthy:
  // the "No password" badge reads `!password`.
  const users = canManage
    ? usersPage.users
    : usersPage.users.map((user) => ({
        ...user,
        password: user.password ? REDACTED : user.password,
      }));


  // A page past the end redirects to the last real page.
  redirectOutOfRange("/system-users", sp, usersPage.meta, usersPage.failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />
      {/* A failed read must not render the empty state. */}
      {usersPage.failed ? (
        <LoadFailed
          description={t("loadFailed")}
          status={usersPage.status}
          failure={usersPage.failure} message={usersPage.message} debug={usersPage.debug}
        />
      ) : (
        <SystemUsersTable
          data={users}
          meta={usersPage.meta}
          shells={shells}
          canManage={canManage}
          canOpenSecurity={can(permissions, "setting", "manage")}
        />
      )}
    </div>
  );
}
