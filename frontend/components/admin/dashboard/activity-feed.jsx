import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { ArrowRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { actionDotClass, humanizeActivity } from "@/lib/activity-log/labels";
import { collapseRepeats } from "@/lib/activity-log/collapse-repeats";
import { pickFeedRows, QUIET_ACTIONS } from "@/lib/activity-log/pick-feed-rows";
import { lowerFirst } from "@/lib/activity-log/lower-first";

/**
 * The last few things anyone did, instead of a running total.
 *
 * "797 recorded actions" answers no question a person arriving here has. A
 * handful of who-did-what does: it is how you notice a login you did not make,
 * or a role changed while you were away.
 *
 * Repeats are collapsed before the cap is applied, which is what makes the
 * handful worth reading — the raw feed is almost entirely one person signing
 * in, and six rows of that would push every other event off the card.
 *
 * `created_at_human` is the API's own relative wording ("3 minutes ago"),
 * already localized, so it is used verbatim rather than re-derived here.
 */
const SHOWN = 6;

export async function ActivityFeed({ entries = [], todayCount = 0 }) {
  const t = await getTranslations("admin.feed");
  // Collapse first, then choose: runs of logins are capped so whatever else
  // happened still gets a row, in the order it happened.
  const rows = pickFeedRows(collapseRepeats(entries, { mergeAcross: QUIET_ACTIONS }), {
    max: SHOWN,
    maxQuiet: 3,
  });

  return (
    // h-full, not just a stretched grid cell: this card sits inside a
    // col-span wrapper, so the wrapper grew to match Access and the card
    // stopped halfway up it.
    <Card className="flex h-full flex-col gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b px-5 py-3.5">
        <h2 className="font-heading text-base leading-snug font-semibold tracking-tight">
          {t("title")}
        </h2>
        <p className="text-sm text-muted-foreground">{t("today", { count: todayCount })}</p>
      </div>

      {rows.length ? (
        <ul className="divide-y">
          {rows.map(({ key, newest, oldest, count }) => {
            // A sentence, not four columns. "test", "Logged in", "50×" and "1
            // minute ago" scattered across a row is four things to assemble in
            // your head; "test logged in 50 times" is one thing to read.
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
                  {/* A collapsed run of fifty says nothing about whether it
                      happened over a minute or a fortnight, which is the part
                      worth knowing. Both ends come from entries already in
                      hand — nothing extra is fetched to say it. */}
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
        <p className="px-5 py-8 text-center text-sm text-muted-foreground">{t("empty")}</p>
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
