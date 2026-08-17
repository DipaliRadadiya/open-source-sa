import { getTranslations } from "next-intl/server";
import { getCentralStatus } from "@/lib/admin/get-central";
import { CentralPanel } from "@/components/admin/central/central-panel";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("central");
  return { title: t("title") };
}

export default async function AdminCentralPage() {
  const [t, { data, failed, status, failure }] = await Promise.all([
    getTranslations("central"),
    getCentralStatus(),
  ]);

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("pageSubtitle")}</p>
      </div>

      {failed ? (
        <LoadFailed status={status} failure={failure} />
      ) : (
        <CentralPanel status={data?.central ?? { enabled: false, token: null }} />
      )}
    </div>
  );
}
