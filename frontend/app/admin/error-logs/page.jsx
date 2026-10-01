import { getFormatter, getTranslations } from "next-intl/server";
import { CircleCheck, ShieldAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  getErrorLogs,
  linesFromSearchParams,
  referenceFromSearchParams,
} from "@/lib/admin/get-error-logs";
import { groupErrorLogs } from "@/lib/admin/group-error-logs";
import { ErrorLogPanel } from "@/components/admin/error-logs/error-log-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("errorLogs");
  return { title: t("title") };
}

export default async function AdminErrorLogsPage({ searchParams }) {
  const sp = await searchParams;
  const lines = linesFromSearchParams(sp);
  const reference = referenceFromSearchParams(sp);

  const [t, format, { data, failed, status, failure, message }] = await Promise.all([
    getTranslations("errorLogs"),
    getFormatter(),
    getErrorLogs(lines, reference),
  ]);

  const entries = data?.error_logs ?? [];
  const groups = groupErrorLogs(entries);

  /* Empty means healthy only for the whole log; during a reference lookup it
     means that reference was not found, so the band is suppressed. */
  const healthy = groups.length === 0 && !reference;
  const showSummary = groups.length > 0 || !reference;

  /* One clock for server and client so relative times match across hydration. */
  const now = new Date();

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {failed ? (
        <LoadFailed status={status} failure={failure} message={message} />
      ) : (
        <>
          {showSummary ? (
          <div className="flex flex-wrap items-center gap-4 rounded-2xl border bg-muted/40 p-4">
            <span
              className={cn(
                "flex size-11 shrink-0 items-center justify-center rounded-xl",
                healthy ? "bg-success/10" : "bg-destructive/10",
              )}
            >
              {healthy ? (
                <CircleCheck className="size-6 text-success" aria-hidden />
              ) : (
                <ShieldAlert className="size-6 text-destructive" aria-hidden />
              )}
            </span>
            <div className="min-w-0 flex-1">
              <p className="font-medium">
                {healthy
                  ? t("summary.healthy")
                  : t("summary.counts", { kinds: groups.length, total: entries.length })}
              </p>
              <p className="text-sm text-muted-foreground">
                {healthy
                  ? t("summary.healthyHint")
                  : t("summary.lastSeen", {
                      when: groups[0]?.last
                        ? format.relativeTime(groups[0].last, now)
                        : t("unknownTime"),
                    })}
              </p>
              {/* Say what is excluded so an empty log does not look broken. */}
              {healthy ? (
                <p className="mt-1 text-sm text-muted-foreground">{t("summary.excluded")}</p>
              ) : null}
            </div>
          </div>
          ) : null}

          {/* Always rendered so its Refresh action stays available. */}
          <NavTransitionProvider>
            <ErrorLogPanel
              groups={groups}
              now={now}
              truncated={Boolean(data?.meta?.truncated)}
              lines={lines}
              reference={reference}
            />
          </NavTransitionProvider>
        </>
      )}
    </div>
  );
}
