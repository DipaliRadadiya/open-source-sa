import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { ArrowRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { actionDotClass, humanizeActivity } from "@/lib/activity/labels";

/**
 * The last few things anyone did, instead of a running total.
 *
 * "797 recorded actions" answers no question a person arriving here has. Ten
 * lines of who-did-what does: it is how you notice a login you did not make, or
 * a role changed while you were away.
 *
 * `created_at_human` is the API's own relative wording ("3 minutes ago"),
 * already localized, so it is used verbatim rather than re-derived here.
 */
export async function ActivityFeed({ entries = [], todayCount = 0 }) {
  const t = await getTranslations("admin.feed");

  return (
    <Card className="flex flex-col gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b px-5 py-3.5">
        <h2 className="font-heading text-base leading-snug font-semibold tracking-tight">
          {t("title")}
        </h2>
        <p className="text-sm text-muted-foreground">{t("today", { count: todayCount })}</p>
      </div>

      {entries.length ? (
        <ul className="divide-y">
          {entries.map((entry) => (
            <li key={entry.id} className="flex items-center gap-3 px-5 py-2.5">
              <span
                className={cn("size-1.5 shrink-0 rounded-full", actionDotClass(entry.action))}
                aria-hidden
              />
              <p className="min-w-0 flex-1 truncate text-sm">
                {entry.description || humanizeActivity(entry.action)}
              </p>
              {/* Says nobody did this — a scheduled reboot and an admin
                  pressing Reboot are the same row otherwise. */}
              {entry.is_system ? (
                <p className="hidden shrink-0 text-xs text-muted-foreground sm:block">
                  {t("system")}
                </p>
              ) : null}
              {entry.user?.username ? (
                <p className="hidden shrink-0 text-xs text-muted-foreground sm:block">
                  {entry.user.username}
                </p>
              ) : null}
              <p className="shrink-0 text-xs whitespace-nowrap text-muted-foreground">
                {entry.created_at_human || ""}
              </p>
            </li>
          ))}
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
