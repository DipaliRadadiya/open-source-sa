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

// Two searches on purpose: the text box filters what is loaded; the reference
// lookup queries the server, since the entry may be older than the loaded lines.
export function ErrorLogPanel({ groups, now, truncated, lines, reference }) {
  const t = useTranslations("errorLogs");
  const tc = useTranslations("common");
  const setQuery = useSetQuery();
  const [search, setSearch] = useState("");

  /* With no entries, search and size have nothing to act on; Refresh stays. */
  const hasEntries = groups.length > 0;

  const visible = useMemo(
    () => groups.filter((group) => groupMatches(group, search)),
    [groups, search],
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start gap-2">
        {/* Kept even with an empty list: a reference may point at an older failure. */}
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

      {/* Say the list is cut short, or the oldest entry reads as the first. */}
      {truncated ? (
        <p className="rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
          {t("truncated", { lines })}
        </p>
      ) : null}

      {/* Own wording: "no failures recorded" would misread as a clean log. */}
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
