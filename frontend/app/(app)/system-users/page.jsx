import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSystemUsersPage } from "@/lib/system-users/get-system-users";
import { getShells } from "@/lib/system-users/get-shells";
import { SystemUsersTable } from "@/components/system-users/system-users-table";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

// Stands in for a password a viewer is not entitled to. Never rendered: the
// only thing that prints the value is the Set-password dialog, which is
// manage-only.
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

  // Feature is permission-gated; without `view`, bounce to the dashboard.
  if (!can(permissions, "system_user", "view")) redirect("/dashboard");

  // Shells come from the server so the picker can never offer one it refuses.
  const [usersPage, shells] = await Promise.all([getSystemUsersPage(query), getShells()]);
  const canManage = can(permissions, "system_user", "manage");

  // The index endpoint returns every account's cleartext OS password, and
  // handing the rows straight to a client component put all of them in the
  // page payload for anyone who can merely VIEW this list — including roles
  // that cannot open the dialog that shows it.
  //
  // The list itself only needs to know WHETHER one is set — the "No password"
  // badge reads `!password` — so a viewer gets a marker that keeps that test
  // true without carrying the secret. It must stay truthy: an empty string
  // would flip every account to "No password", which is a different lie.
  // Managers get the real value, which is what PasswordReveal shows.
  const users = canManage
    ? usersPage.users
    : usersPage.users.map((user) => ({
        ...user,
        password: user.password ? REDACTED : user.password,
      }));

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>
      {/* `failed` was never read, so a 500 or a rejected shape rendered the
          empty state: "No system users yet" over a server that has accounts,
          with an Add button inviting you to create one that already exists.
          Every sibling list page branches here; this one did not. */}
      {usersPage.failed ? (
        <LoadFailed
          description={t("loadFailed")}
          status={usersPage.status}
          failure={usersPage.failure}
        />
      ) : (
        <SystemUsersTable
          data={users}
          meta={usersPage.meta}
          shells={shells}
          canManage={canManage}
        />
      )}
    </div>
  );
}
