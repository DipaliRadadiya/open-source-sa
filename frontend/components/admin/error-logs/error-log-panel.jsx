"use client";

import { useMemo, useState } from "react";
import { SearchX } from "lucide-react";
import { useTranslations } from "next-intl";
import { groupMatches } from "@/lib/admin/group-error-logs";
import { LINE_OPTIONS } from "@/lib/schemas/error-log";
import { useSetQuery } from "@/hooks/use-set-query";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ErrorGroupRow } from "@/components/admin/error-logs/error-group-row";
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
 * Search is in-memory because the endpoint has no filter parameters at all —
 * `lines` is the only thing it accepts. That one goes through the URL like
 * every other list control in the panel, so changing it re-runs the server
 * component instead of fetching from the browser.
 */
export function ErrorLogPanel({ groups, now, truncated, lines }) {
  const t = useTranslations("errorLogs");
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
      <div className="flex flex-wrap items-center gap-2">
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

      {/* Nothing to list, and the summary band above has already said so in
          better words than a second empty box could. */}
      {!hasEntries ? null : visible.length === 0 ? (
        <EmptyState
          icon={SearchX}
          title={t("noMatchesTitle")}
          description={t("noMatchesDescription")}
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
