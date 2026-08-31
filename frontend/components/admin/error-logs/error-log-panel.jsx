"use client";

import { useMemo, useState } from "react";
import { SearchX } from "lucide-react";
import { useTranslations } from "next-intl";
import { groupMatches } from "@/lib/admin/group-error-logs";
import { LINE_OPTIONS } from "@/lib/schemas/error-log";
import { useSetQuery } from "@/hooks/use-set-query";
import { EmptyState } from "@/components/data-table/empty-state";
import { Button } from "@/components/ui/button";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ErrorGroupRow } from "@/components/admin/error-logs/error-group-row";
import { ReferenceLookup } from "@/components/admin/error-logs/reference-lookup";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * The grouped error list plus its controls.
 *
 * Two searches, deliberately, because they answer different questions:
 *
 * The text box filters in memory. It narrows what is already on screen by
 * exception, route, feature or operation — a browsing tool.
 *
 * The reference lookup goes through the URL to the server's `?reference=`
 * parameter, because the entry being asked for is frequently NOT on screen:
 * someone is holding a reference from a failure older than the last 100 lines,
 * and an in-memory filter over those lines could only ever answer "not found".
 *
 * `lines` goes through the URL for the same reason every other list control in
 * the panel does — it re-runs the server component rather than fetching from
 * the browser.
 */
export function ErrorLogPanel({ groups, now, truncated, lines, reference }) {
  const t = useTranslations("errorLogs");
  const tc = useTranslations("common");
  const setQuery = useSetQuery();
  const [search, setSearch] = useState("");

  /* "Nothing recorded" is the normal state here, and it still needs its action:
     an admin who has just seen the panel misbehave wants to re-ask, not reload
     the browser. Search and the size selector have nothing to act on with an
     empty list, so they go — Refresh stays. */
  const hasEntries = groups.length > 0;

  const visible = useMemo(
    () => groups.filter((group) => groupMatches(group, search)),
    [groups, search],
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start gap-2">
        {/* Kept even with an empty list, unlike the text search: "nothing
            recorded in the last 100 lines" is exactly when someone arrives
            holding a reference from a failure that happened yesterday. */}
        <ReferenceLookup
          value={reference}
          onSubmit={(value) => setQuery({ reference: value })}
          onClear={() => setQuery({ reference: null })}
        />
        {hasEntries ? (
          <LocalSearchInput
            value={search}
            onChange={setSearch}
            placeholder={t("searchPlaceholder")}
          />
        ) : null}
        <div className="ml-auto flex items-center gap-2">
          {hasEntries ? (
            <>
              <span className="hidden whitespace-nowrap text-sm text-muted-foreground sm:inline">
                {t("linesLabel")}
              </span>
              <Select
                value={String(lines)}
                onValueChange={(value) => setQuery({ lines: value })}
              >
                <SelectTrigger className="w-[5.5rem]" aria-label={t("linesLabel")}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {LINE_OPTIONS.map((option) => (
                    <SelectItem key={option} value={String(option)}>
                      {option}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </>
          ) : null}
          <RefreshButton />
        </div>
      </div>

      {/* Say out loud that the list is cut short. Without this the oldest entry
          on screen reads as the oldest that exists, and an admin concludes the
          problem started at a time it did not. */}
      {truncated ? (
        <p className="rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
          {t("truncated", { lines })}
        </p>
      ) : null}

      {/* A lookup that found nothing needs its own words. The healthy-state
          band above says "no failures recorded", which read against a specific
          reference means the opposite of what happened — the log is fine, that
          one reference is not in it. */}
      {!hasEntries && reference ? (
        <EmptyState
          icon={SearchX}
          title={t("referenceNotFoundTitle")}
          description={t("referenceNotFoundDescription")}
        />
      ) : !hasEntries ? null : visible.length === 0 ? (
        <EmptyState
          icon={SearchX}
          title={t("noMatchesTitle")}
          description={t("noMatchesDescription")}
          action={
            search ? (
              <Button variant="outline" onClick={() => setSearch("")}>
                {tc("clearFilters")}
              </Button>
            ) : null
          }
        />
      ) : (
        <div className="space-y-3">
          {visible.map((group) => (
            <ErrorGroupRow key={group.key} group={group} now={now} />
          ))}
        </div>
      )}
    </div>
  );
}
