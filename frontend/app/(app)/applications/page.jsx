import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { FlaskConical } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplications } from "@/lib/applications/get-applications";
import { previewApplications } from "@/lib/applications/preview-fixture";
import { ApplicationsTable } from "@/components/applications/applications-table";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("applications");
  return { title: t("title") };
}

export default async function ApplicationsPage({ searchParams }) {
  const sp = await searchParams;
  const preview = Boolean(sp?.preview);
  const [permissions, t, result] = await Promise.all([
    getPermissions(),
    getTranslations("applications"),
    preview
      ? Promise.resolve({ applications: previewApplications, failed: false })
      : getApplications(),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.failed) return <LoadFailed description={t("loadFailed")} />;

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>
      {preview ? (
        <p className="flex items-center gap-2 rounded-lg border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-warning">
          <FlaskConical className="size-4 shrink-0" />
          {t("preview.notice")}
        </p>
      ) : null}
      <ApplicationsTable
        applications={result.applications}
        canManage={can(permissions, "application", "manage")}
        preview={preview}
      />
    </div>
  );
}
