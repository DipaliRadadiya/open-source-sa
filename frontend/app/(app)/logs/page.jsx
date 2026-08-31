import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { ScrollText } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getLogSources } from "@/lib/logs/get-log-sources";
import { getLog } from "@/lib/logs/get-log";
import { cookies } from "next/headers";
import { FOLLOW_COOKIE } from "@/lib/logs/follow-preference";
import { LogsPanel } from "@/components/logs/logs-panel";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";

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

  // Read here rather than in the client: the panel would otherwise start the
  // tail, then stop it once it read the preference, which is the flicker this
  // is meant to remove.
  const followPreference = cookieStore.get(FOLLOW_COOKIE)?.value ?? null;

  if (!can(permissions, "logs", "view")) redirect("/dashboard");

  const { logs: sources, failed } = await getLogSources();
  // Default to the first source the panel can actually open, so a box where
  // most logs need elevated access still lands on something useful.
  const selected =
    sources.find((s) => s.key === sp.source)?.key ??
    sources.find((s) => s.readable)?.key ??
    sources[0]?.key ??
    null;

  const initial = selected
    ? await getLog(selected, { lines: DEFAULT_LINES })
    : { status: "ok", log: null };

  const lockedCount = sources.filter((s) => !s.readable).length;

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">
          {lockedCount > 0
            ? t("subtitleWithLocked", { count: lockedCount, total: sources.length })
            : t("subtitle")}
        </p>
      </div>

      {/* "We couldn't ask" before "there are none": an unanswered request must
          never render as a claim about what's on the server. */}
      {failed ? (
        <LoadFailed description={t("loadFailedSources")} />
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
          initialLines={DEFAULT_LINES}
          followPreference={followPreference}
        />
      )}
    </div>
  );
}
