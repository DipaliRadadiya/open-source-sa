import { getTranslations } from "next-intl/server";
import { ScrollText } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getLogSources } from "@/lib/logs/get-log-sources";
import { getLog } from "@/lib/logs/get-log";
import { cookies } from "next/headers";
import { FOLLOW_COOKIE, LINES_COOKIE } from "@/lib/logs/follow-preference";
import { parseLinesPref } from "@/lib/logs/app-log-prefs";
import { LogsPanel } from "@/components/logs/logs-panel";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

const DEFAULT_LINES = 200;

export async function generateMetadata() {
  const t = await getTranslations("logs");
  return { title: t("title") };
}

export default async function LogsPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t, cookieStore] = await Promise.all([
    getPermissions(),
    getTranslations("logs"),
    cookies(),
  ]);

  // Read on the server so the tail does not start and then stop on hydration.
  const followPreference = cookieStore.get(FOLLOW_COOKIE)?.value ?? null;
  const lines = parseLinesPref(cookieStore.get(LINES_COOKIE)?.value, DEFAULT_LINES);

  if (!can(permissions, "logs", "view")) return <PermissionDenied title={t("title")} />;
  // Emptying a log is a different trust from reading one.
  const canManage = can(permissions, "logs", "manage");

  const { logs: sources, failed, status, failure, message } = await getLogSources();
  // Default to the first readable source.
  const selected =
    sources.find((s) => s.key === sp.source)?.key ??
    sources.find((s) => s.readable)?.key ??
    sources[0]?.key ??
    null;

  const initial = selected
    ? await getLog(selected, { lines })
    : { status: "ok", log: null };

  const lockedCount = sources.filter((s) => !s.readable).length;
  // The clock the source list's "written just now" dots are drawn against.
  const renderedAt = new Date().getTime();

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("title")}
        subtitle={
          lockedCount > 0
            ? t("subtitleWithLocked", { count: lockedCount, total: sources.length })
            : t("subtitle")
        }
      />

      {/* A failed request must never render as "there are no logs". */}
      {failed ? (
        <LoadFailed description={t("loadFailedSources")} status={status} failure={failure} message={message} />
      ) : sources.length === 0 ? (
        <EmptyState
          icon={ScrollText}
          title={t("noSources.title")}
          description={t("noSources.body")}
        />
      ) : (
        <LogsPanel
          sources={sources}
          selected={selected}
          initial={initial}
          initialLines={lines}
          followPreference={followPreference}
          renderedAt={renderedAt}
          canManage={canManage}
        />
      )}
    </div>
  );
}
