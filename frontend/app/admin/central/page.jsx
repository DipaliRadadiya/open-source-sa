import { getTranslations } from "next-intl/server";
import { getCentralStatus } from "@/lib/admin/get-central";
import { CentralPanel } from "@/components/admin/central/central-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("central");
  return { title: t("title") };
}

export default async function AdminCentralPage() {
  const [t, { data, failed, status, failure, message }] = await Promise.all([
    getTranslations("central"),
    getCentralStatus(),
  ]);

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("pageSubtitle")} />

      {failed ? (
        <LoadFailed status={status} failure={failure} message={message} />
      ) : (
        <CentralPanel status={data?.central ?? { enabled: false, token: null }} />
      )}
    </div>
  );
}
