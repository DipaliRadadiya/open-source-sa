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

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("errorLogs");
  return { title: t("title") };
}

export default async function AdminErrorLogsPage({ searchParams }) {
  const sp = await searchParams;
  const lines = linesFromSearchParams(sp);
  const reference = referenceFromSearchParams(sp);

  const [t, format, { data, failed, status, failure }] = await Promise.all([
    getTranslations("errorLogs"),
    getFormatter(),
    getErrorLogs(lines, reference),
  ]);

  const entries = data?.error_logs ?? [];
  const groups = groupErrorLogs(entries);

  /* "Nothing recorded" only means the server is healthy when the whole log was
     asked for. During a reference lookup the same empty result means the log is
     fine and that one reference is not in it — so the band is suppressed and
     the panel says which of the two happened. */
  const healthy = groups.length === 0 && !reference;
  const showSummary = groups.length > 0 || !reference;

  /* One clock for the whole page. Relative times are formatted against this on
     both sides of hydration; letting the client read its own clock re-renders
     every row and can print a different answer than the server just did. */
  const now = new Date();

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {failed ? (
        <LoadFailed status={status} failure={failure} />
      ) : (
        <>
          {/* Same neutral summary band as System Health: status is carried by
              the coloured icon, not by tinting the whole surface. */}
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
              {/* An empty page here is the normal, correct state, and an admin
                  who does not know what is excluded reads it as "logging is
                  broken". Say what is not recorded, where it is reassuring. */}
              {healthy ? (
                <p className="mt-1 text-sm text-muted-foreground">{t("summary.excluded")}</p>
              ) : null}
            </div>
          </div>
          ) : null}

          {/* Rendered even with nothing to show: the panel keeps its Refresh
              action, which would otherwise disappear exactly when someone came
              here to re-check. */}
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
