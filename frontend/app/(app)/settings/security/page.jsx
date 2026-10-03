import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSettings } from "@/lib/settings/get-settings";
import { SshForm } from "@/components/settings/ssh-form";
import { changedFor } from "@/lib/settings/changed-for";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function SettingsSecurityPage() {
  const [permissions, t, { data, lastChanged, failed, status, failure, message }] = await Promise.all([
    getPermissions(),
    getTranslations("settings"),
    getSettings(),
  ]);

  const canManage = can(permissions, "setting", "manage");

  if (failed || !data?.security)
    return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  return (
    <SshForm
      security={data.security}
      canManage={canManage}
      changedBy={await changedFor(lastChanged, "security")}
    />
  );
}
