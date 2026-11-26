import Link from "@/components/ui/app-link";

import { useMemo, useRef, useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import {
  Code2,
  LayoutTemplate,
  PackageOpen,
  Download,
  Search,
  TriangleAlert,
  X,
  Database,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { siteTypeLogo } from "@/lib/applications/site-type-logo";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { groupForType, groupsWithTypes } from "@/lib/applications/type-categories";
import { rangeLabel } from "@/lib/runtime/version-range";
import { acceptedEngines } from "@/lib/applications/database-readiness";
import { blockersAreFixable } from "@/lib/applications/blockers";
import { TruncatedText } from "@/components/ui/truncated-text";
import { dockerMemoryText } from "@/lib/docker/memory-text";

function TypeIcon({ type, className }) {
  const Icon =
    type.method === "git"
      ? Code2
      : type.has_installer
        ? PackageOpen
        : LayoutTemplate;
  return <Icon className={className} aria-hidden />;
}

function TypeTile({ type, dimmed, size = "h-9 w-14" }) {
  if (type.recipe?.icon_data_uri || siteTypeLogo(type.name)) {
    return <SiteTypeLogo name={type.name} iconDataUri={type.recipe?.icon_data_uri} size={size} className={cn(dimmed && "opacity-50")} />;
  }
  return (
    <span
      className={cn(
        "flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary",
        dimmed && "opacity-50",
      )}
    >
      <TypeIcon type={type} className="size-5" />
    </span>
  );
}

function blockerItem(blocker, type, { t, tEngines, format }) {
  if (blocker?.kind === "database") {
    const names = engineNames(blocker.engines ?? acceptedEngines(type) ?? [], tEngines);
    return names.length ? format.list(names, { type: "disjunction" }) : t("form.aDatabase");
  }

  if (blocker?.kind !== "runtime") return null;

  const name = RUNTIME_NAMES[blocker.runtime];
  if (!name) return null;
  if (blocker.suggest) return `${name} ${blocker.suggest}`;
  return blocker.label ? `${name} ${blocker.label}` : name;
}

function engineNames(engines, tEngines) {
  return (Array.isArray(engines) ? engines : []).map((engine) =>
    tEngines.has(`engines.${engine}`) ? tEngines(`engines.${engine}`) : engine,
  );
}

const RUNTIME_NAMES = { php: "PHP", node: "Node" };

function blockerLine(type, { t, tEngines, format }) {
  const blockers = Array.isArray(type?.blockers) ? type.blockers : [];
  if (blockers.length === 0) return legacyBlockerLabel(type, { t, tEngines, format });

  const items = blockers.map((blocker) => blockerItem(blocker, type, { t, tEngines, format }));
  if (items.some((item) => !item)) return null;

  return t("form.needs", { items: format.list(items, { type: "conjunction" }) });
}

function legacyBlockerLabel(type, { t, tEngines, format }) {
  if (type?.unavailable_code === "database") {
    const engines = acceptedEngines(type) ?? [];
    const names = engines.map((engine) =>
      tEngines.has(`engines.${engine}`) ? tEngines(`engines.${engine}`) : engine,
    );
    return names.length
      ? t("form.blockedDatabaseEngines", { engines: format.list(names, { type: "disjunction" }) })
      : t("form.blockedDatabase");
  }
  if (type?.unavailable_code !== "runtime") return null;

  if (type.php_version_range) {
    const range = rangeLabel(type.php_version_range);
    return range ? t("form.blockedPhpVersion", { range }) : t("form.blockedPhp");
  }
  if (type.node_version_range) {
    const range = rangeLabel(type.node_version_range);
    return range ? t("form.blockedNodeVersion", { range }) : t("form.blockedNode");
  }
  return null;
}

function BlockerReasons({ type }) {
  const reasons = (Array.isArray(type?.blockers) ? type.blockers : [])
    .map((blocker) => blocker.reason)
    .filter(Boolean);

  if (reasons.length === 0) return type?.unavailable_reason ?? null;
  if (reasons.length === 1) return reasons[0];

  return (
    <span className="flex flex-col gap-1">
      {reasons.map((reason) => (
        <span key={reason}>{reason}</span>
      ))}
    </span>
  );
}

const RUNTIME_FIX = {
  php: { href: "/php", label: "form.installPhpVersion" },
  node: { href: "/node", label: "form.installNodeVersion" },
};

const DATABASE_FIX = { href: "/databases", label: "form.installDatabaseEngine" };

function blockerFixes(type) {
  const blockers = Array.isArray(type?.blockers) ? type.blockers : [];

  if (blockers.length === 0) {
    if (type?.unavailable_code === "database") return [DATABASE_FIX];
    if (type?.unavailable_code !== "runtime") return [];
    if (type.php_version_range) return [RUNTIME_FIX.php];
    if (type.node_version_range) return [RUNTIME_FIX.node];
    return [];
  }

  return blockers
    .map((blocker) => {
      if (blocker.kind === "database") return DATABASE_FIX;
      if (blocker.kind === "runtime") return RUNTIME_FIX[blocker.runtime] ?? null;
      return null;
    })
    .filter(Boolean);
}

export function SiteTypePicker({ types = [], value, onChange, searchPlaceholder, renderNoMatch }) {
  const t = useTranslations("applications");
  const tg = useTranslations("applications.guided");
  const tc = useTranslations("common");
  const tEngines = useTranslations("databases");
  const format = useFormatter();
  const [query, setQuery] = useState("");
  const searchRef = useRef(null);

  const ordered = useMemo(
    () =>
      [...types].sort(
        (a, b) =>
          Number(b.popular) - Number(a.popular) || a.title.localeCompare(b.title),
      ),
    [types],
  );
  const groups = useMemo(() => groupsWithTypes(types), [types]);
  const popularCount = useMemo(() => types.filter((type) => type.popular).length, [types]);

  const [activeGroup, setActiveGroup] = useState(() => (popularCount ? "popular" : "all"));

  const groupLabel = (key) =>
    t(`form.category${key.charAt(0).toUpperCase()}${key.slice(1)}`);

  const matchesQuery = (type, term) =>
    [type.title, type.tagline, type.category]
      .filter(Boolean)
      .some((text) => text.toLowerCase().includes(term));

  const inGroup = (type) =>
    activeGroup === "all"
      ? true
      : activeGroup === "popular"
        ? Boolean(type.popular)
        : groupForType(type) === activeGroup;

  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    const pool = ordered.filter(inGroup);
    return term ? pool.filter((type) => matchesQuery(type, term)) : pool;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ordered, query, activeGroup]);

  const hiddenByGroup = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term || filtered.length) return 0;
    return ordered.filter((type) => matchesQuery(type, term)).length;
  }, [ordered, query, filtered.length]);

  const selectedType = types.find((type) => type.name === value);

  const fixes = useMemo(() => {
    const byHref = new Map();
    for (const type of filtered) {
      if (type.available) continue;
      for (const fix of blockerFixes(type)) {
        if (!byHref.has(fix.href)) byHref.set(fix.href, fix);
      }
    }
    return [...byHref.values()];
  }, [filtered]);

  if (selectedType) {
    return (
      <div className="flex items-center gap-3 rounded-xl border bg-muted/30 p-3">
        <TypeTile type={selectedType} size="h-8 w-12" />
        <span className="min-w-0 flex-1">
          <span className="block truncate font-medium">{selectedType.title}</span>
          {selectedType.tagline ? (
            <span className="block text-xs text-muted-foreground">
              {selectedType.tagline}
            </span>
          ) : null}
        </span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="shrink-0"
          onClick={() => {
            setQuery("");
            onChange("");
          }}
        >
          {tg("change")}
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      <div className="relative">
        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          ref={searchRef}
          value={query}
          onChange={(event) => {
            if (!query.trim() && event.target.value.trim() && activeGroup !== "all") setActiveGroup("all");
            setQuery(event.target.value);
          }}
          placeholder={searchPlaceholder ?? t("form.typeSearch")}
          aria-label={searchPlaceholder ?? t("form.typeSearch")}
          className="ps-9 pe-9"
        />
        {query ? (
          <button
            type="button"
            onClick={() => {
              setQuery("");
              searchRef.current?.focus();
            }}
            aria-label={tc("clearSearch")}
            className="absolute end-2 top-1/2 flex size-6 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
          >
            <X className="size-4" />
          </button>
        ) : null}
      </div>

      {groups.length > 1 ? (
        <div className="flex flex-wrap gap-1.5">
          {[
            ...(popularCount ? [{ key: "popular", count: popularCount }] : []),
            ...groups,
            { key: "all", count: ordered.length },
          ].map((group) => {
            const active = group.key === activeGroup;
            return (
              <button
                key={group.key}
                type="button"
                aria-pressed={active}
                onClick={() => setActiveGroup(group.key)}
                className={cn(
                  "rounded-full border px-3 py-1 text-xs font-medium transition-colors",
                  active
                    ? "border-primary bg-primary/10 text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))] dark:text-primary"
                    : "border-transparent bg-muted text-muted-foreground hover:bg-accent hover:text-accent-foreground",
                )}
              >
                {groupLabel(group.key)}
              </button>
            );
          })}
        </div>
      ) : null}

      {filtered.length ? (
        <div className="grid grid-cols-1 gap-4 @xl:grid-cols-2 @3xl:grid-cols-3">
          {filtered.map((type) => {
            const disabled = !type.available;
            const choosable = !disabled || blockersAreFixable(type);
            const Card = choosable ? "button" : "div";
            const cardProps = choosable
              ? { type: "button", onClick: () => onChange(type.name) }
              : {};

            return (
              <Card
                key={type.name}
                {...cardProps}
                className={cn(
                  "flex w-full items-center gap-3 rounded-xl border bg-muted/40 p-2.5 text-left transition-colors",
                  !choosable && "border-dashed",
                  choosable &&
                    "hover:border-primary/40 hover:bg-card hover:shadow-[0_1px_2px_rgb(0_0_0/0.04),0_2px_6px_rgb(0_0_0/0.05)] focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none",
                )}
              >
                <TypeTile type={type} dimmed={!choosable} size="h-8 w-10" />

                <span className={cn("min-w-0 flex-1")}>
                  <TruncatedText
                    className={cn("text-sm font-medium", !choosable && "opacity-60")}
                  >
                    {type.title}
                  </TruncatedText>
                  {disabled && type.unavailable_reason ? (
                    <span className="mt-0.5 flex items-center gap-1 text-xs leading-4 text-warning">
                      <TriangleAlert className="size-3 shrink-0" />
                      <TruncatedText tooltip={<BlockerReasons type={type} />}>
                        {blockerLine(type, { t, tEngines, format }) ?? type.unavailable_reason}
                      </TruncatedText>
                    </span>
                  ) : type.tagline ? (
                    <TruncatedText className="mt-0.5 text-xs leading-4 text-muted-foreground">
                      {type.tagline}
                    </TruncatedText>
                  ) : null}
                  {type.recipe ? (
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                      {t("recipes.containers", { count: type.recipe.services.length })}
                      {type.recipe.memory_floor ? <> · {t("recipes.memoryFloor", { memory: dockerMemoryText(type.recipe.memory_floor) })}</> : null}
                    </span>
                  ) : null}
                </span>

              </Card>
            );
          })}
        </div>
      ) : query.trim() && !hiddenByGroup && renderNoMatch ? (
        renderNoMatch(query.trim())
      ) : (
        <div className="rounded-xl border border-dashed px-3 py-8 text-center">
          <p className="text-sm text-muted-foreground">
            {hiddenByGroup
              ? t("form.typeNoResultsInCategory", {
                  query,
                  category: groupLabel(activeGroup),
                  count: hiddenByGroup,
                })
              : t("form.typeNoResults", { query })}
          </p>
          {hiddenByGroup ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="mt-3"
              onClick={() => setActiveGroup("all")}
            >
              {t("form.typeSearchAllCategories")}
            </Button>
          ) : null}
        </div>
      )}

      {fixes.length ? (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
          {fixes.map((fix) => (
            <Link
              key={fix.href}
              href={fix.href}
              className="flex items-center gap-1.5 text-xs font-medium text-primary underline-offset-4 hover:underline"
            >
              {fix.href === "/databases" ? (
                <Database className="size-3.5 shrink-0" />
              ) : (
                <Download className="size-3.5 shrink-0" />
              )}
              {t(fix.label)}
            </Link>
          ))}
        </div>
      ) : null}
    </div>
  );
}
