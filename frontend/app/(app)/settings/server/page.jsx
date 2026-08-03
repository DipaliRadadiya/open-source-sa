import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSettings } from "@/lib/settings/get-settings";
import { getTimezones } from "@/lib/settings/get-timezones";
import { GeneralForm } from "@/components/settings/general-form";
import { changedFor } from "@/lib/settings/changed-for";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function SettingsServerPage() {
  const [permissions, t, { data, lastChanged, failed }, timezones] = await Promise.all([
    getPermissions(),
    getTranslations("settings"),
    getSettings(),
    getTimezones(),
  ]);

  const canManage = can(permissions, "setting", "manage");

  if (failed || !data?.general)
    return <LoadFailed description={t("loadFailed")} />;

  return (
    <GeneralForm
      general={data.general}
      canManage={canManage}
      timezones={timezones}
      changedBy={await changedFor(lastChanged, "general")}
    />
  );
}
