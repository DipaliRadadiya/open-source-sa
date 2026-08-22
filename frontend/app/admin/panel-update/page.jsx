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
  const [t, state, branding] = await Promise.all([
    getTranslations("panelUpdate"),
    getPanelUpdate(),
    getBranding(),
  ]);

  const subtitle = t("subtitle", { brand: branding.name });

  // The heading travels with the panel because "Check again" sits beside it and
  // shares its state; only the load-failure branch, which has no button to
  // offer, renders a heading of its own.
  if (!state) {
    return (
      <div className="max-w-3xl space-y-6">
        <PageHeader title={t("title")} subtitle={subtitle} />
        <LoadFailed description={t("loadFailed")} />
      </div>
    );
  }

  // Not keyed by locale on purpose: the panel resumes an in-flight update via
  // polling, and a re-mount would drop that. The only localized backend text
  // here (step/reason titles) appears mid-run, when nobody is switching
  // languages.
  return <PanelUpdatePanel initialState={state} title={t("title")} subtitle={subtitle} />;
}
