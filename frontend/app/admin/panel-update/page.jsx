import { getTranslations } from "next-intl/server";
import { getPanelUpdate } from "@/lib/admin/get-panel-update";
import { getBranding } from "@/lib/branding/get-branding";
import { PanelUpdatePanel } from "@/components/admin/panel-update/panel-update-panel";
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

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle", { brand: branding.name })}</p>
      </div>

      {/* Not keyed by locale on purpose: the panel resumes an in-flight update
          via polling, and a re-mount would drop that. The only localized
          backend text here (step/reason titles) appears mid-run, when nobody is
          switching languages. */}
      {!state ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <PanelUpdatePanel initialState={state} />
      )}
    </div>
  );
}
