import { redirect } from "next/navigation";
import { Globe2 } from "lucide-react";
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

export default async function ApplicationsPage({ searchParams }) {
  const sp = await searchParams;
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
      {/* Opening a site that no longer exists lands here, because the list is
          the only place left to go. Saying so on arrival is what separates a
          redirect from being silently teleported somewhere you did not ask for. */}
      {sp?.gone ? (
        <div className="flex items-start gap-2.5 rounded-lg border bg-muted/40 p-3 text-sm">
          <Globe2 className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
          <span>
            <span className="font-medium">{t("missing.title")}</span>{" "}
            <span className="text-muted-foreground">{t("missing.description")}</span>
          </span>
        </div>
      ) : null}
      <ApplicationsTable
        applications={result.applications}
        canManage={can(permissions, "application", "manage")}
      />
    </div>
  );
}
