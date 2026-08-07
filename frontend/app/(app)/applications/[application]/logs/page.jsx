import { PageHeader } from "@/components/ui/page-header";
import { ScrollText } from "lucide-react";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import {
  getApplicationLogs,
  getApplicationLog,
} from "@/lib/applications/get-application-logs";
import { ApplicationLogsPanel } from "@/components/applications/logs/application-logs-panel";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

const DEFAULT_LINES = 200;

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.logs"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationLogsPage({ params, searchParams }) {
  const { application: id } = await params;
  const [sp, permissions, appPermissions, t, result] = await Promise.all([
    searchParams,
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.logs"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} />;

  const application = result.application;
  // app_log is its own grant — a site's access log and the machine's auth.log
  // are different things to be trusted with.
  if (!can(appPermissions, "app_log", "view", "application")) {
    redirect(`/applications/${id}`);
  }
  const settled = application.status === "active";

  const { logs: sources, failed } = settled
    ? await getApplicationLogs(id)
    : { logs: [], failed: false };

  // Land on a source that has data if one exists, so a fresh site doesn't open
  // to an empty access log when its error log has something.
  const selected =
    sources.find((s) => s.key === sp?.source)?.key ??
    sources.find((s) => s.exists)?.key ??
    sources[0]?.key ??
    null;

  const initial = selected
    ? await getApplicationLog(id, selected, { lines: DEFAULT_LINES })
    : { status: "ok", log: null };

  return (
    <div className="space-y-6">
      <PageHeader
        backHref="/applications"
        backLabel={t("back")}
        title={t("pageTitle")}
        subtitle={t("pageSubtitle")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : failed ? (
        <LoadFailed description={t("loadFailed")} />
      ) : sources.length === 0 ? (
        <EmptyState
          icon={ScrollText}
          title={t("noSources.title")}
          description={t("noSources.body")}
        />
      ) : (
        <ApplicationLogsPanel
          appId={id}
          sources={sources}
          selected={selected}
          initial={initial}
          initialLines={DEFAULT_LINES}
        />
      )}
    </div>
  );
}
