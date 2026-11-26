import { HeadIcon } from "@/components/ui/head-icon";
import Link from "@/components/ui/app-link";
import { getLocale, getTranslations } from "next-intl/server";
import { ArrowRight, CircleAlert, History } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { actionDotClass, humanizeActivity } from "@/lib/activity-log/labels";
import { collapseRepeats } from "@/lib/activity-log/collapse-repeats";
import { pickFeedRows, QUIET_ACTIONS } from "@/lib/activity-log/pick-feed-rows";
import { isMachineActor, SIGN_IN_ACTIONS } from "@/lib/admin/sign-in-facts";
import { lowerFirst } from "@/lib/activity-log/lower-first";

const SHOWN = 5;

export async function ActivityFeed({ entries = [], todayCount = 0, failed = false, todayKnown = true, people = null }) {
  const [t, locale] = await Promise.all([getTranslations("admin.feed"), getLocale()]);
  const changes = entries.filter((entry) => !SIGN_IN_ACTIONS.includes(entry.action));
  const rows = pickFeedRows(collapseRepeats(changes, { mergeAcross: QUIET_ACTIONS }), {
    max: SHOWN,
    maxQuiet: 3,
  });
  const actorName = (entry) => {
    if (entry.is_system) return t("system");
    if (isMachineActor(entry, people)) return t("central");
    return entry.user?.username ?? t("someone");
  };

  return (
    <Card className="flex h-full flex-col gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b px-5 py-3.5">
        <div className="flex items-center gap-3">
          <HeadIcon icon={History} />
          <h2 className="text-[15px] leading-snug font-semibold tracking-tight">
            {t("title")}
          </h2>
        </div>
        <p className="text-sm text-muted-foreground">
          {todayKnown ? t("today", { count: todayCount }) : "—"}
        </p>
      </div>

      {rows.length ? (
        <ul className="divide-y">
          {rows.map(({ key, newest, oldest, count }) => {
            const what = lowerFirst(newest.description || humanizeActivity(newest.action), locale);
            const who = actorName(newest);
            return (
              <li key={key} className="flex items-start gap-3 px-5 py-3">
                <span
                  className={cn(
                    "mt-1.5 size-1.5 shrink-0 rounded-full",
                    actionDotClass(newest.action),
                  )}
                  aria-hidden
                />
                <div className="min-w-0 flex-1 space-y-0.5">
                  <p className="text-sm">
                    {count > 1
                      ? t("sentenceRepeated", { who, what, count })
                      : t("sentenceOnce", { who, what })}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {count > 1 && oldest?.created_at_human && oldest.id !== newest.id && oldest.created_at_human !== newest.created_at_human
                      ? t("between", {
                          first: oldest.created_at_human,
                          last: newest.created_at_human || "",
                        })
                      : t("mostRecent", { when: newest.created_at_human || "" })}
                  </p>
                </div>
              </li>
            );
          })}
        </ul>
      ) : (
        <div className="p-5">
          <EmptyState compact icon={failed ? CircleAlert : History} badge={null} title={failed ? t("failed") : entries.length ? t("onlySignIns") : t("empty")} />
        </div>
      )}

      <div className="mt-auto border-t bg-muted/20 px-5 py-2.5">
        <Link
          href="/admin/activity-log"
          className="inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
          {t("viewAll")}
          <ArrowRight className="size-3.5" aria-hidden />
        </Link>
      </div>
    </Card>
  );
}
