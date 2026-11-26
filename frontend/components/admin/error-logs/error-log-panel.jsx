"use client";

import { useMemo, useState } from "react";
import { Hash, SearchX } from "lucide-react";
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
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { cn } from "@/lib/utils";
import { SELECT_WELL } from "@/components/data-table/toolbar-well";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { FILTER_TOGGLE_ITEM } from "@/lib/theme/filter-toggle";

export function ErrorLogPanel({ groups, now, truncated, lines, reference }) {
  const t = useTranslations("errorLogs");
  const tc = useTranslations("common");
  const setQuery = useSetQuery();
  const [search, setSearch] = useState("");
  const [referenceOpen, setReferenceOpen] = useState(false);
  const [kind, setKind] = useState("all");
  const kindOf = (group) => (group.kind === "operation" ? "operation" : "api");
  const counts = useMemo(
    () => ({
      all: groups.length,
      api: groups.filter((group) => kindOf(group) === "api").length,
      operation: groups.filter((group) => kindOf(group) === "operation").length,
    }),
    [groups],
  );

  const hasEntries = groups.length > 0;

  const visible = useMemo(
    () => groups.filter((group) => (kind === "all" || kindOf(group) === kind) && groupMatches(group, search)),
    [groups, search, kind],
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        {hasEntries ? (
          <LocalSearchInput value={search} onChange={setSearch} placeholder={t("searchPlaceholder")} />
        ) : null}

        {counts.api && counts.operation ? (
          <ToggleGroup
            type="single"
            value={kind}
            onValueChange={(next) => next && setKind(next)}
            className="flex-wrap gap-1"
            aria-label={t("kind.label")}
          >
            {["all", "api", "operation"].map((value) => (
              <ToggleGroupItem key={value} value={value} className={FILTER_TOGGLE_ITEM}>
                {t(`kind.${value}`, { count: counts[value] })}
              </ToggleGroupItem>
            ))}
          </ToggleGroup>
        ) : null}

        <div className="ml-auto flex items-center gap-2">
          <Popover open={referenceOpen} onOpenChange={setReferenceOpen}>
            <PopoverTrigger asChild>
              <Button variant={reference ? "secondary" : "outline"}>
                <Hash className="size-4" />
                {reference ? t("referenceActive", { reference: reference.slice(0, 8) }) : t("referenceFind")}
              </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-[min(26rem,calc(100vw-2rem))] space-y-2">
              <p className="text-sm font-medium">{t("referenceLabel")}</p>
              <p className="text-xs text-muted-foreground">{t("referenceHint")}</p>
              <ReferenceLookup
                value={reference}
                autoFocus
                onSubmit={(value) => {
                  setReferenceOpen(false);
                  setQuery({ reference: value });
                }}
                onClear={() => setQuery({ reference: null })}
              />
            </PopoverContent>
          </Popover>

          {hasEntries ? (
            <Select value={String(lines)} onValueChange={(value) => setQuery({ lines: value })}>
              <SelectTrigger className={cn(SELECT_WELL, "w-auto min-w-28")} aria-label={t("linesLabel")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {LINE_OPTIONS.map((option) => (
                  <SelectItem key={option} value={String(option)}>
                    {t("linesOption", { count: option })}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : null}
          <RefreshButton />
        </div>
      </div>

      {truncated ? (
        <p className="rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
          {t("truncated", { lines })}
        </p>
      ) : null}

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
            search || kind !== "all" ? (
              <Button variant="outline" onClick={() => { setSearch(""); setKind("all"); }}>
                {tc("clearFilters")}
              </Button>
            ) : null
          }
        />
      ) : (
        <div className="divide-y overflow-hidden rounded-xl border bg-card">
          {visible.map((group) => (
            <ErrorGroupRow key={group.key} group={group} now={now} />
          ))}
        </div>
      )}
    </div>
  );
}
