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

// Category glyph for a type with no logo; types with artwork use SiteTypeLogo.
function TypeIcon({ type, className }) {
  const Icon =
    type.method === "git"
      ? Code2
      : type.has_installer
        ? PackageOpen
        : LayoutTemplate;
  return <Icon className={className} aria-hidden />;
}

// Logos get no tile; only the fallback glyph keeps one.
function TypeTile({ type, dimmed, size = "h-9 w-14" }) {
  if (siteTypeLogo(type.name)) {
    return <SiteTypeLogo name={type.name} size={size} className={cn(dimmed && "opacity-50")} />;
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

// A short reason for a greyed card, or null to keep the API's full sentence (too long
// for a 190px card). Unrecognised codes keep the server's sentence.
function blockerItem(blocker, type, { t, tEngines, format }) {
  if (blocker?.kind === "database") {
    const names = engineNames(blocker.engines ?? acceptedEngines(type) ?? [], tEngines);
    return names.length ? format.list(names, { type: "disjunction" }) : t("form.aDatabase");
  }

  if (blocker?.kind !== "runtime") return null;

  const name = RUNTIME_NAMES[blocker.runtime];
  if (!name) return null;
  // Name `suggest`, not the range: the range's lower bound may be end-of-life and hidden.
  if (blocker.suggest) return `${name} ${blocker.suggest}`;
  return blocker.label ? `${name} ${blocker.label}` : name;
}

/** Engine keys as the names the databases pages already use. */
function engineNames(engines, tEngines) {
  return (Array.isArray(engines) ? engines : []).map((engine) =>
    tEngines.has(`engines.${engine}`) ? tEngines(`engines.${engine}`) : engine,
  );
}

const RUNTIME_NAMES = { php: "PHP", node: "Node" };

// Every blocker in one line so no errand is missed. Null when any blocker has no short
// form, so the server's sentence is shown.
function blockerLine(type, { t, tEngines, format }) {
  const blockers = Array.isArray(type?.blockers) ? type.blockers : [];
  if (blockers.length === 0) return legacyBlockerLabel(type, { t, tEngines, format });

  const items = blockers.map((blocker) => blockerItem(blocker, type, { t, tEngines, format }));
  if (items.some((item) => !item)) return null;

  return t("form.needs", { items: format.list(items, { type: "conjunction" }) });
}

// The same line for payloads without a `blockers` array.
function legacyBlockerLabel(type, { t, tEngines, format }) {
  if (type?.unavailable_code === "database") {
    // Name the accepted engines (SQL pair is the fallback for older APIs); the
    // disjunction list gives each locale's own "or".
    const engines = acceptedEngines(type) ?? [];
    const names = engines.map((engine) =>
      tEngines.has(`engines.${engine}`) ? tEngines(`engines.${engine}`) : engine,
    );
    return names.length
      ? t("form.blockedDatabaseEngines", { engines: format.list(names, { type: "disjunction" }) })
      : t("form.blockedDatabase");
  }
  if (type?.unavailable_code !== "runtime") return null;

  // Include the range so the reader knows upgrade vs install. `rangeLabel` returns "" when unbounded.
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

/** One sentence per blocker, stacked, for the card's tooltip. */
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

// Every fix link, so a type with two blockers shows both.
function blockerFixes(type) {
  const blockers = Array.isArray(type?.blockers) ? type.blockers : [];

  if (blockers.length === 0) {
    // The pre-`blockers` shape, kept for anything not fed by the create page.
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
      // A web-server refusal has nothing to install, so no link.
      return null;
    })
    .filter(Boolean);
}

// A logo grid while nothing is chosen, collapsing to one row with Change once chosen.
// Unavailable types stay visible, greyed, with their reason and fix link.
export function SiteTypePicker({ types = [], value, onChange }) {
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

  // `popular` is a backend flag, not usage data; All when nothing is flagged.
  const [activeGroup, setActiveGroup] = useState(() => (popularCount ? "popular" : "all"));

  // Uses the chip's own label, so the empty state matches the visible words.
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

  // Chip and search narrow together, so the grid never contradicts the active chip.
  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    const pool = ordered.filter(inGroup);
    return term ? pool.filter((type) => matchesQuery(type, term)) : pool;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ordered, query, activeGroup]);

  // Matches the chip is hiding; zero means no match anywhere.
  const hiddenByGroup = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term || filtered.length) return 0;
    return ordered.filter((type) => matchesQuery(type, term)).length;
  }, [ordered, query, filtered.length]);

  const selectedType = types.find((type) => type.name === value);

  // One entry per destination, not per card, and only for cards on screen.
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

  // Change clears the field: type-specific fields must not stay mounted under a half-made choice.
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
      {/* Search first: with this many cards it is for people who know the name. */}
      <div className="relative">
        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          ref={searchRef}
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder={t("form.typeSearch")}
          aria-label={t("form.typeSearch")}
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

      {/* Four buckets from type-categories.js; empty ones are not rendered. Chips, not Tabs:
          they filter one grid rather than switching panels. */}
      {groups.length > 1 ? (
        <div className="flex flex-wrap gap-1.5">
          {/* Popular first, All last. */}
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
        /* Container queries: the grid sits beside a 20rem summary panel, so the
           viewport width says nothing about its room. */
        <div className="grid grid-cols-1 gap-4 @xl:grid-cols-2 @3xl:grid-cols-3">
          {filtered.map((type) => {
            const disabled = !type.available;
            // Blocked but choosable when every blocker is installable from here; Create
            // stays refused until the server reports them present.
            const choosable = !disabled || blockersAreFixable(type);
            // A `div`, not a disabled `button`: screen readers skip text in disabled buttons.
            const Card = choosable ? "button" : "div";
            const cardProps = choosable
              ? { type: "button", onClick: () => onChange(type.name) }
              : {};

            return (
              <Card
                key={type.name}
                {...cardProps}
                className={cn(
                  // Logo beside the words, keeping cards two lines tall.
                  "flex w-full items-center gap-3 rounded-xl border bg-muted/40 p-2.5 text-left transition-colors",
                  // Dashed means unchoosable; a choosable card must look pressable.
                  !choosable && "border-dashed",
                  choosable &&
                    "hover:border-primary/40 hover:bg-card hover:shadow-[0_1px_2px_rgb(0_0_0/0.04),0_2px_6px_rgb(0_0_0/0.05)] focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none",
                )}
              >
                {/* No "Popular" badge: the order already says it. */}
                <TypeTile type={type} dimmed={!choosable} size="h-8 w-10" />

                <span className={cn("min-w-0 flex-1")}>
                  {/* TruncatedText offers the clipped rest on hover, only when clipped. */}
                  <TruncatedText
                    className={cn("text-sm font-medium", !choosable && "opacity-60")}
                  >
                    {type.title}
                  </TruncatedText>
                  {/* On a blocked card the reason replaces the tagline. */}
                  {disabled && type.unavailable_reason ? (
                    <span className="mt-0.5 flex items-center gap-1 text-xs leading-4 text-warning">
                      <TriangleAlert className="size-3 shrink-0" />
                      {/* The bubble shows one sentence per blocker, stacked, so none is skimmed. */}
                      <TruncatedText tooltip={<BlockerReasons type={type} />}>
                        {blockerLine(type, { t, tEngines, format }) ?? type.unavailable_reason}
                      </TruncatedText>
                    </span>
                  ) : type.tagline ? (
                    <TruncatedText className="mt-0.5 text-xs leading-4 text-muted-foreground">
                      {type.tagline}
                    </TruncatedText>
                  ) : null}
                </span>

              </Card>
            );
          })}
        </div>
      ) : (
        /* Search matches only outside the active chip: name it and offer one click back. */
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

      {/* Every fix link once, deduplicated by destination. Matched on
          `unavailable_code`, which the API sends so it need not be inferred. */}
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
