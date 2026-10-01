import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { ArrowRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { actionDotClass, humanizeActivity } from "@/lib/activity-log/labels";
import { collapseRepeats } from "@/lib/activity-log/collapse-repeats";
import { pickFeedRows, QUIET_ACTIONS } from "@/lib/activity-log/pick-feed-rows";
import { lowerFirst } from "@/lib/activity-log/lower-first";

// Repeats are collapsed before the cap so a run of logins cannot fill the card.
const SHOWN = 6;

export async function ActivityFeed({ entries = [], todayCount = 0, failed = false, todayKnown = true }) {
  const t = await getTranslations("admin.feed");
  // Collapse first, then choose; quiet actions (logins) are capped.
  const rows = pickFeedRows(collapseRepeats(entries, { mergeAcross: QUIET_ACTIONS }), {
    max: SHOWN,
    maxQuiet: 3,
  });

  return (
    // h-full: the card sits inside a col-span wrapper that stretches, not the card.
    <Card className="flex h-full flex-col gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b px-5 py-3.5">
        <h2 className="font-heading text-base leading-snug font-semibold tracking-tight">
          {t("title")}
        </h2>
        {/* A failed stats read shows "—", never "0 today". */}
        <p className="text-sm text-muted-foreground">
          {todayKnown ? t("today", { count: todayCount }) : "—"}
        </p>
      </div>

      {rows.length ? (
        <ul className="divide-y">
          {rows.map(({ key, newest, oldest, count }) => {
            // One sentence ("test logged in 50 times") rather than columns.
            const what = lowerFirst(newest.description || humanizeActivity(newest.action));
            const who = newest.is_system ? t("system") : (newest.user?.username ?? t("someone"));
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
                  {/* A collapsed run shows its time span, from entries already loaded. */}
                  <p className="text-xs text-muted-foreground">
                    {count > 1 && oldest?.created_at_human && oldest.id !== newest.id
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
        /* A failed read must not render as "nothing has happened". */
        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
          {failed ? t("failed") : t("empty")}
        </p>
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
