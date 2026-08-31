import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSettings } from "@/lib/settings/get-settings";
import { getRebootPresets } from "@/lib/settings/get-reboot-presets";
import { getRebootStatus } from "@/lib/settings/get-reboot-status";
import { MaintenanceCard } from "@/components/settings/maintenance-card";
import { changedFor } from "@/lib/settings/changed-for";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function SettingsMaintenancePage() {
  const [permissions, t, { data, lastChanged, failed }, presets, pending] = await Promise.all([
    getPermissions(),
    getTranslations("settings"),
    getSettings(),
    getRebootPresets(),
    // Never blocks the page: a restart that cannot be read about is still a
    // page worth showing, and the card says so rather than claiming "none".
    getRebootStatus().catch(() => ({ failed: true, data: null })),
  ]);

  const canManage = can(permissions, "setting", "manage");

  if (failed || !data) return <LoadFailed description={t("loadFailed")} />;

  // One card, three sections. Split across three cards these were three mostly
  // empty boxes with three save bars, for six settings that are one decision.
  return (
    <MaintenanceCard
      updates={data.updates}
      schedule={data.reboot_schedule}
      rebootRequired={Boolean(data.updates?.reboot_required)}
      presets={presets.data}
      presetsFailed={presets.failed}
      pendingReboot={pending.data?.reboot ?? null}
      pendingRebootFailed={Boolean(pending.failed)}
      canManage={canManage}
      changedBy={await changedFor(lastChanged, "updates")}
    />
  );
}
