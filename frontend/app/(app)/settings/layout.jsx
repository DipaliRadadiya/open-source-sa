import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSettings } from "@/lib/settings/get-settings";
import { SettingsTabs } from "@/components/settings/settings-tabs";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("settings");
  return { title: t("title") };
}

export default async function SettingsLayout({ children }) {
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("settings"),
  ]);

  if (!can(permissions, "setting", "view")) return <PermissionDenied title={t("title")} />;
  const { data } = await getSettings();

  const badges = {
    security: data?.security?.permit_root_login === "yes" ? "warning" : null,
    maintenance: data?.updates?.reboot_required ? "info" : null,
  };

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />

      <div className="space-y-6">
          <SettingsTabs badges={badges} />
          {children}
        </div>
    </div>
  );
}
