import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSettings } from "@/lib/settings/get-settings";
import { SettingsTabs } from "@/components/settings/settings-tabs";

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

  if (!can(permissions, "setting", "view")) redirect("/dashboard");

  // The badges are the only reason the layout reads settings — the point of a
  // dot on a tab is that it's visible from the section you're already on.
  // `getSettings` is request-cached, so the open section shares this call.
  const { data } = await getSettings();

  const badges = {
    // Root login by password is the one combination here that hands a
    // brute-forcer a shell; everything else is a preference.
    security: data?.security?.permit_root_login === "yes" ? "warning" : null,
    maintenance: data?.updates?.reboot_required ? "info" : null,
  };

  return (
    <div className="space-y-6">
      <div className="space-y-0.5">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {/* One width for the tabs AND every section: the strip only frames the
          cards if it ends where they end. Set here so nothing shifts as you
          move between sections. */}
      {/* The tabs need to know whether the section below them has unsaved
          edits, and only a shared provider can carry that across the boundary. */}
      <div className="max-w-[48rem] space-y-6">
          <SettingsTabs badges={badges} />
          {children}
        </div>
    </div>
  );
}
