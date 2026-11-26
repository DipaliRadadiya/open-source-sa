import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSystemUsersPage } from "@/lib/system-users/get-system-users";
import { getShells } from "@/lib/system-users/get-shells";
import { getSettings } from "@/lib/settings/get-settings";
import { SystemUsersTable } from "@/components/system-users/system-users-table";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

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
  const [usersPage, shells, settings] = await Promise.all([
    getSystemUsersPage(query),
    getShells(),
    can(permissions, "setting", "view") ? getSettings() : null,
  ]);
  const sshPasswordOff = settings?.data?.security?.password_authentication === false;
  const canManage = can(permissions, "system_user", "manage");

  // SECURITY: the API returns cleartext passwords, so viewers get a placeholder. Keep it truthy, the No password badge checks !password.
  const users = canManage
    ? usersPage.users
    : usersPage.users.map((user) => ({
        ...user,
        password: user.password ? REDACTED : user.password,
      }));

  redirectOutOfRange("/system-users", sp, usersPage.meta, usersPage.failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />
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
          sshPasswordOff={sshPasswordOff}
        />
      )}
    </div>
  );
}
