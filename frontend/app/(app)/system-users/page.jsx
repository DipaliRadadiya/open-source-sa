import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSystemUsers } from "@/lib/system-users/get-system-users";
import { getShells } from "@/lib/system-users/get-shells";
import { SystemUsersTable } from "@/components/system-users/system-users-table";

export const dynamic = "force-dynamic";

export default async function SystemUsersPage() {
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("systemUsers"),
  ]);

  // Feature is permission-gated; without `view`, bounce to the dashboard.
  if (!can(permissions, "system_user", "view")) redirect("/dashboard");

  // Shells come from the server so the picker can never offer one it refuses.
  const [users, shells] = await Promise.all([getSystemUsers(), getShells()]);
  const canManage = can(permissions, "system_user", "manage");

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>
      <SystemUsersTable data={users} shells={shells} canManage={canManage} />
    </div>
  );
}
