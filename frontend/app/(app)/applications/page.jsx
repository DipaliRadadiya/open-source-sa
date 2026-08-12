import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplications } from "@/lib/applications/get-applications";
import { ApplicationsTable } from "@/components/applications/applications-table";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("applications");
  return { title: t("title") };
}

export default async function ApplicationsPage() {
  const [permissions, t, result] = await Promise.all([
    getPermissions(),
    getTranslations("applications"),
    getApplications(),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.failed) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} />;

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>
      <ApplicationsTable
        applications={result.applications}
        canManage={can(permissions, "application", "manage")}
      />
    </div>
  );
}
