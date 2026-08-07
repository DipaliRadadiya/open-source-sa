import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getStorageDestinations } from "@/lib/storage/get-storage";
import { DestinationsCard } from "@/components/integrations/storage/destinations-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("storage");
  return { title: t("title") };
}

export default async function StorageIntegrationsPage() {
  const [permissions, t, list] = await Promise.all([
    getPermissions(),
    getTranslations("storage"),
    getStorageDestinations(),
  ]);

  if (!can(permissions, "storage", "view")) redirect("/dashboard");
  const canManage = can(permissions, "storage", "manage");

  if (list.failed) return <LoadFailed description={t("loadFailed")} />;

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />
      <div className="max-w-4xl">
        <DestinationsCard destinations={list.destinations} canManage={canManage} />
      </div>
    </div>
  );
}
