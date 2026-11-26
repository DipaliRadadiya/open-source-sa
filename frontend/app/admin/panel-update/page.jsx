import { getTranslations } from "next-intl/server";
import { getPanelUpdate } from "@/lib/admin/get-panel-update";
import { getBranding } from "@/lib/branding/get-branding";
import { PanelUpdatePanel } from "@/components/admin/panel-update/panel-update-panel";
import { PageHeader } from "@/components/ui/page-header";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("panelUpdate");
  return { title: t("title") };
}

export default async function AdminPanelUpdatePage() {
  const [t, update, branding] = await Promise.all([
    getTranslations("panelUpdate"),
    getPanelUpdate(),
    getBranding(),
  ]);

  const state = update.state;
  const subtitle = t("subtitle", { brand: branding.name });

  if (!state) {
    return (
      <div className="space-y-6">
        <PageHeader title={t("title")} subtitle={subtitle} />
        <LoadFailed
          description={t("loadFailed")}
          status={update.status}
          failure={update.failure} message={update.message} debug={update.debug}
        />
      </div>
    );
  }

  // Not keyed by locale: a remount would stop the polling of a running update.
  return <PanelUpdatePanel initialState={state} title={t("title")} subtitle={subtitle} />;
}
