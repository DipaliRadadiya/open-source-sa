"use client";

import Link from "next/link";
import { useFormatter, useTranslations } from "next-intl";
import { ChevronRight } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { Badge } from "@/components/ui/badge";
import { CardList, CardListItem } from "@/components/data-table/card-list";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { ApplicationStatusBadge, ApplicationStatusNotes } from "@/components/applications/application-status-badge";

/**
 * The sites list on a narrow screen.
 *
 * The table is six columns wide, so on a phone it showed the name and nothing
 * else — no status, no type, and the row menu was off the right edge with
 * nothing hinting at a swipe. The one thing you open this page to do was the
 * one thing you could not reach.
 *
 * Two lines of identity, one line of facts. The status badge shares the facts
 * line rather than taking a band of its own — the services card puts its badge
 * top-right instead, but a service is called "nginx" and a site is called
 * "Company Blog (Staging)": at 320 a badge up there leaves the name about six
 * characters. The name wins the wide line; the badge is legible either way.
 * The globe icon is dropped here for the same reason — it is identical on every
 * row, so it spends 44px saying nothing.
 */
export function ApplicationsCards({ applications = [], canManage = false }) {
  const t = useTranslations("applications");
  const format = useFormatter();

  return (
    <CardList>
      {applications.map((application) => (
        <CardListItem key={application.id}>
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                <Link
                  href={`/applications/${application.id}`}
                  prefetch={false}
                  className="inline-flex min-w-0 items-center gap-1 font-medium text-primary underline-offset-4 hover:underline"
                >
                  <span className="truncate">{application.name}</span>
                  <ChevronRight className="size-3.5 shrink-0" />
                </Link>
                {/* A copy and the site it copies sit next to each other under
                    near-identical names — same reason as the table. */}
                {application.is_staging ? (
                  <Badge variant="warning" className="shrink-0 font-normal">
                    {t("stagingBadge")}
                  </Badge>
                ) : null}
              </div>
              <p className="truncate font-mono text-xs text-muted-foreground">{application.domain}</p>
            </div>
            {/* shrink-0 so the menu keeps its place however long the name is —
                it is the reason this card exists. */}
            <div className="-me-2 -mt-1 shrink-0">
              <ApplicationRowActions application={application} canManage={canManage} />
            </div>
          </div>

          {/* Spacing separates the facts, not middots: the line wraps at 320 and
              a separator left stranded at the end of a wrapped line reads as a
              missing value. */}
          <p className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t pt-3 text-xs text-muted-foreground">
            <ApplicationStatusBadge application={application} />
            <span className="truncate text-foreground">
              {application.site_type_title ?? application.site_type}
            </span>
            <span className="truncate font-mono">{application.system_user?.username ?? "—"}</span>
            {/* Same fact as the table's Size column — the cards are this list
                below lg, not a different list. Without the measurement date
                there is no room to qualify it, so an unmeasured site is left
                out rather than shown as a dash that reads like zero bytes. */}
            {formatBytes(application.directory_size_bytes, format) ? (
              <span className="whitespace-nowrap tabular-nums">
                {formatBytes(application.directory_size_bytes, format)}
              </span>
            ) : null}
            <span className="whitespace-nowrap">{application.created_at_human ?? "—"}</span>
          </p>

          <ApplicationStatusNotes application={application} className="-mt-1" />
        </CardListItem>
      ))}
    </CardList>
  );
}
