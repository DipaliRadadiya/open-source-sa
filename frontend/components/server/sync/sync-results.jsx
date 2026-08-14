"use client";

import { useMemo, useState } from "react";
import { ChevronRight, EyeOff, SearchX, Undo2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { SYNC_RESOURCE_TYPES } from "@/lib/schemas/sync";
import { ignoreKey } from "@/lib/server/sync-selection";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { SyncEvidence } from "@/components/server/sync/sync-evidence";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { cn } from "@/lib/utils";

const ACTION_VARIANTS = {
  adopted: "success",
  skipped: "secondary",
  failed: "destructive",
  found: "outline",
};

/**
 * Everything one run found, in one table.
 *
 * One table rather than nine sections: the types are wildly uneven — a box can
 * have two hundred vhosts and three certificates — and per-type sections would
 * each need their own pagination to cope with that, turning one list into nine
 * lists with nine sets of controls. The per-type counts that sections would
 * have given for free are carried by the filter chips above instead, so no
 * count is hidden to show another.
 */
export function SyncResults({
  items,
  ignoredKeys,
  canManage,
  onIgnore,
  onUnignore,
  pendingKey,
}) {
  const t = useTranslations("sync");
  const [search, setSearch] = useState("");
  const [types, setTypes] = useState([]);
  const [expanded, setExpanded] = useState(() => new Set());

  const countsByType = useMemo(() => {
    const counts = new Map();
    for (const item of items) {
      counts.set(item.resource_type, (counts.get(item.resource_type) ?? 0) + 1);
    }
    return counts;
  }, [items]);

  // Only the types this run actually produced, in the backend's dependency
  // order — a chip reading "Workers 0" is a control that does nothing.
  const presentTypes = useMemo(
    () => SYNC_RESOURCE_TYPES.filter((type) => countsByType.has(type)),
    [countsByType],
  );

  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return items.filter((item) => {
      if (types.length && !types.includes(item.resource_type)) return false;
      if (!needle) return true;
      return (
        item.resource_key.toLowerCase().includes(needle) ||
        (item.reason ?? "").toLowerCase().includes(needle)
      );
    });
  }, [items, search, types]);

  function toggleExpanded(id) {
    setExpanded((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <LocalSearchInput
          value={search}
          onChange={setSearch}
          placeholder={t("results.searchPlaceholder")}
        />
        <p className="text-sm text-muted-foreground">
          {t("results.showing", { shown: visible.length, total: items.length })}
        </p>
      </div>

      {presentTypes.length > 1 ? (
        <ToggleGroup
          type="multiple"
          value={types}
          onValueChange={setTypes}
          variant="outline"
          size="sm"
          className="flex flex-wrap justify-start gap-2"
        >
          {presentTypes.map((type) => (
            <ToggleGroupItem key={type} value={type} className="gap-1.5">
              {t(`types.${type}`)}
              <span className="text-xs text-muted-foreground">{countsByType.get(type)}</span>
            </ToggleGroupItem>
          ))}
        </ToggleGroup>
      ) : null}

      {visible.length === 0 ? (
        <EmptyState
          icon={SearchX}
          title={t("results.noMatches")}
          description={t("results.noMatchesHint")}
        />
      ) : (
        <div className="overflow-x-auto rounded-xl border">
          <Table>
            <TableHeader>
              <TableRow className="bg-muted/40 hover:bg-muted/40">
                <TableHead className="w-10" />
                <TableHead className="w-[16%]">{t("results.columns.type")}</TableHead>
                <TableHead>{t("results.columns.name")}</TableHead>
                <TableHead className="w-[12%]">{t("results.columns.outcome")}</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {visible.map((item) => {
                const key = ignoreKey(item);
                const ignored = ignoredKeys.has(key);
                const isOpen = expanded.has(item.id);

                return [
                  <TableRow
                    key={item.id}
                    className={cn(ignored && "opacity-55")}
                  >
                    <TableCell className="align-top">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-expanded={isOpen}
                        aria-label={t("results.toggleDetails", { name: item.resource_key })}
                        onClick={() => toggleExpanded(item.id)}
                      >
                        <ChevronRight
                          className={cn("size-4 transition-transform", isOpen && "rotate-90")}
                          aria-hidden
                        />
                      </Button>
                    </TableCell>
                    <TableCell className="align-top text-sm text-muted-foreground">
                      {t(`types.${item.resource_type}`)}
                    </TableCell>
                    <TableCell className="align-top">
                      <span className={cn("font-mono text-sm break-all", ignored && "line-through")}>
                        {item.resource_key}
                      </span>
                      {/* The reason is already a full sentence in the reader's
                          language — the backend localizes it — so one line of
                          it here is the whole explanation, not a label. */}
                      {item.reason ? (
                        <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                          {item.reason}
                        </p>
                      ) : null}
                      {ignored ? (
                        <p className="mt-0.5 text-xs font-medium text-muted-foreground">
                          {t("results.ignoredNote")}
                        </p>
                      ) : null}
                    </TableCell>
                    <TableCell className="align-top">
                      <Badge variant={ACTION_VARIANTS[item.action] ?? "outline"}>
                        {t(`outcomes.${item.action}`)}
                      </Badge>
                    </TableCell>
                    <TableCell className="align-top">
                      {canManage ? (
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          className="size-7"
                          disabled={pendingKey === key}
                          aria-label={
                            ignored
                              ? t("results.restore", { name: item.resource_key })
                              : t("results.ignore", { name: item.resource_key })
                          }
                          onClick={() => (ignored ? onUnignore(item) : onIgnore(item))}
                        >
                          {ignored ? (
                            <Undo2 className="size-4" aria-hidden />
                          ) : (
                            <EyeOff className="size-4" aria-hidden />
                          )}
                        </Button>
                      ) : null}
                    </TableCell>
                  </TableRow>,
                  isOpen ? (
                    <TableRow key={`${item.id}-details`} className="hover:bg-transparent">
                      <TableCell colSpan={5} className="p-0">
                        <SyncEvidence item={item} />
                      </TableCell>
                    </TableRow>
                  ) : null,
                ];
              })}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  );
}
