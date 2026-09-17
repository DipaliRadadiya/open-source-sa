import Link from "next/link";

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

/**
 * The category glyph for a type with no logo of its own.
 *
 * It is what the whole list used to be: one of three Lucide icons chosen from
 * `method`, so seventeen different applications were drawn as three shapes and
 * the icon column told you nothing you could not read in the name beside it.
 * A type that has artwork goes through SiteTypeLogo instead — see TypeTile.
 */
function TypeIcon({ type, className }) {
  const Icon =
    type.method === "git"
      ? Code2
      : type.has_installer
        ? PackageOpen
        : LayoutTemplate;
  return <Icon className={className} aria-hidden />;
}

/**
 * The mark at the top of a card, or beside the chosen type.
 *
 * A logo needs no tile: it brings its own colour and shape, and a box around
 * each one turns a wall of logos into a wall of boxes. Only the fallback glyph
 * keeps a tile, because a lone grey icon floating in a card has nothing to hold
 * it.
 */
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

/**
 * Where to go and clear the thing blocking this type, or null.
 *
 * Only for a blocker the reader can actually clear. A card the backend greyed
 * for a web server has nothing to install here, and a link that cannot help is
 * worse than none.
 *
 * Which runtime is read off the type's own range rather than a field added for
 * this: a type blocked on `runtime` that declares a PHP range was blocked by
 * PHP.
 *
 * A missing database engine is the same kind of blocker and was missing the
 * same way out. The footer already said where to go, but only once for the
 * whole list — so the card that actually told you the problem was the one place
 * with no answer to it.
 */
/**
 * Three words for why a card is greyed, or null to fall back to the API's own
 * sentence.
 *
 * The sentence is right but it is a sentence: "This application needs MySQL /
 * MariaDB, which is not installed on this server" is three lines under a
 * 190px card, and on a server with nothing installed it is the same three
 * lines under twelve of them. Clipped to one line every card read "This
 * application needs…", which is a row of identical stubs — technically the
 * reason, practically noise.
 *
 * Keyed on `unavailable_code` and the range the type declares, exactly as
 * blockerFix is. Anything else — a web-server refusal, something added
 * upstream tomorrow — keeps the server's sentence, because a short label we
 * have not written is worse than a long one that is true.
 */
function blockerItem(blocker, type, { t, tEngines, format }) {
  if (blocker?.kind === "database") {
    const names = engineNames(blocker.engines ?? acceptedEngines(type) ?? [], tEngines);
    return names.length ? format.list(names, { type: "disjunction" }) : t("form.aDatabase");
  }

  if (blocker?.kind !== "runtime") return null;

  const name = RUNTIME_NAMES[blocker.runtime];
  if (!name) return null;
  /*
   * The version to install, not the range to satisfy.
   *
   * "Needs Node 20.19 – 24" is what the card said, and a user read the first
   * number, went to install Node 20.19, and found it was not on offer — the
   * 20 line is end-of-life and the install list hides those on purpose. They
   * reported that n8n could not be installed at all.
   *
   * `suggest` is the newest version this panel WILL install that the range
   * accepts, so the card names something that exists. The range is still the
   * answer when nothing on offer fits: there is no version to name, and
   * hiding that behind a tidier sentence would be the same failure again.
   */
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

/**
 * Every blocker in one line — "Needs Node 24 and MySQL or MariaDB".
 *
 * One line and not a list, because this sits under a 190px card. The tooltip
 * carries the sentences; this carries the shopping list, and its whole job is
 * that nobody leaves this page believing they have seen one errand when there
 * are two.
 *
 * Null when any blocker has no short form — a web-server refusal, or a code
 * added upstream tomorrow. Then the server's own sentence is shown instead,
 * because a label we have not written is worse than a long one that is true.
 */
function blockerLine(type, { t, tEngines, format }) {
  const blockers = Array.isArray(type?.blockers) ? type.blockers : [];
  if (blockers.length === 0) return legacyBlockerLabel(type, { t, tEngines, format });

  const items = blockers.map((blocker) => blockerItem(blocker, type, { t, tEngines, format }));
  if (items.some((item) => !item)) return null;

  return t("form.needs", { items: format.list(items, { type: "conjunction" }) });
}

/**
 * The same line for a type that carries no `blockers` array.
 *
 * The array is added by the create page. Anything else rendering this picker —
 * or a cached payload from before it existed — still gets a short label rather
 * than a three-line sentence under a card.
 */
function legacyBlockerLabel(type, { t, tEngines, format }) {
  if (type?.unavailable_code === "database") {
    /*
     * The engines by name, from the catalogue's own `accepted_engines`.
     *
     * "Needs a database" is the same sentence for a WordPress that wants MySQL
     * or MariaDB and a NodeBB that wants MongoDB or PostgreSQL, and someone
     * whose server already runs one of the four cannot tell from it whether
     * they are one click away or nowhere near. The list is the answer, and it
     * is sent — `acceptedEngines` reads the field and falls back to the SQL
     * pair only for an API old enough not to have it.
     *
     * `format.list` with a disjunction, not " / ": "MySQL or MariaDB" in
     * English, "MySQL ou MariaDB" in French, and the separator each locale
     * actually uses rather than a slash we picked.
     */
    const engines = acceptedEngines(type) ?? [];
    const names = engines.map((engine) =>
      tEngines.has(`engines.${engine}`) ? tEngines(`engines.${engine}`) : engine,
    );
    return names.length
      ? t("form.blockedDatabaseEngines", { engines: format.list(names, { type: "disjunction" }) })
      : t("form.blockedDatabase");
  }
  if (type?.unavailable_code !== "runtime") return null;

  /*
   * With the VERSIONS, not just the runtime's name.
   *
   * "Needs Node" is true of a server that has Node 18 installed and of one
   * that has none, and those are different problems with different fixes —
   * the first is a version bump, the second an install. The type declares the
   * range it runs on, both ends inclusive and either end optional, so the
   * card can say "Needs Node 20.19 – 24" and the reader knows before clicking
   * whether what they have is close.
   *
   * `rangeLabel` returns "" for a range with neither end, which is a type that
   * runs on anything — then the plain name is all there is to say.
   */
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

/**
 * Every place this type sends you, not just the first.
 *
 * Plural now: a type blocked on both a runtime and an engine has two errands,
 * and naming one of them is how they get discovered one visit at a time.
 *
 * A runtime blocker with no `suggest` still links to its page — there is no
 * version we can offer, but the page is where that becomes visible, and a card
 * that names a dead end with no way to look at it is worse than one link that
 * confirms it.
 */
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
      // A web-server refusal has nothing to install. A link that cannot help
      // is worse than none.
      return null;
    })
    .filter(Boolean);
}

/**
 * The application type: a grid of logos while nothing is chosen, one row after.
 *
 * This was a searchable dropdown for a while, and the reason was real — 17
 * cards ate a screen of height before the form began. But that trade was made
 * when a card was a grey glyph and a name, so a list row lost almost nothing.
 * The types have had their own brand marks since 2026-09-14, and a logo is the
 * thing people scan by; hiding them behind a trigger turned a recognition task
 * back into a reading task. A user said so, in those words, within a day.
 *
 * The height objection is answered by making the grid a STAGE rather than a
 * permanent block: it owns section 1 only until something is picked, then
 * collapses to a single row with Change beside it, and the form below is no
 * further down than it was with the dropdown. Nothing is behind an extra click
 * either way — the dropdown needed one to open.
 *
 * Every behaviour the dropdown had is kept: search, popular first, the tagline
 * that teaches what Statamic is, and an unavailable type shown greyed WITH its
 * reason and the link that clears it, rather than hidden.
 */
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

  /*
   * The grid opens on Popular, not on all seventeen.
   *
   * There is no usage data to pick a category with — this panel collects none
   * — and choosing one by intuition would hide sixteen applications behind a
   * guess. `popular` is the backend's own flag, the one honest signal we have,
   * and it already orders this list. Seven cards is three rows; All is the
   * next chip along, and typing searches the whole catalogue regardless.
   *
   * A server whose catalogue flags nothing opens on All rather than on an
   * empty grid.
   */
  const [activeGroup, setActiveGroup] = useState(() => (popularCount ? "popular" : "all"));

  // The chip's own label, so the empty state names the filter using the exact
  // words on the chip the reader can see rather than a second vocabulary.
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

  /*
   * The chip and the search box narrow together.
   *
   * Typing used to search the WHOLE catalogue, chip be damned, to avoid
   * answering someone who typed "PrestaShop" with "no results" just because
   * the CMS chip happened to be active. The intent was right; the execution
   * left the chip lit while being ignored, so the grid showed a CMS app under
   * an active "Tools" filter and the screen contradicted itself.
   *
   * Both now apply, and the stranding case is answered where it belongs — in
   * the empty state, which counts the matches the chip is hiding and offers
   * one click to widen (see `hiddenByGroup` below). The filter tells the
   * truth, and nobody who typed a real name hits a dead end.
   */
  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    const pool = ordered.filter(inGroup);
    return term ? pool.filter((type) => matchesQuery(type, term)) : pool;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ordered, query, activeGroup]);

  /*
   * How many the search WOULD find if the chip were not narrowing it. Zero
   * means the term matches nothing anywhere, which is a different sentence
   * from "nothing in Tools" and gets different words below.
   */
  const hiddenByGroup = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term || filtered.length) return 0;
    return ordered.filter((type) => matchesQuery(type, term)).length;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ordered, query, filtered.length]);

  const selectedType = types.find((type) => type.name === value);

  // One entry per DESTINATION, not per blocked card: "install a database
  // engine" is the same answer for all eight of them.
  const fixes = useMemo(() => {
    const byHref = new Map();
    for (const type of types) {
      if (type.available) continue;
      for (const fix of blockerFixes(type)) {
        if (!byHref.has(fix.href)) byHref.set(fix.href, fix);
      }
    }
    return [...byHref.values()];
  }, [types]);

  /*
   * Chosen: one row, and the grid is gone.
   *
   * Change clears the field rather than reopening a picker over the top of it,
   * because the rest of the form is driven by this value — the fields in
   * section 3 belong to the type, and leaving them mounted under a half-made
   * decision is how a WordPress admin password ends up submitted with a Craft
   * site.
   */
  if (selectedType) {
    return (
      <div className="flex items-center gap-3 rounded-xl border bg-muted/30 p-3">
        <TypeTile type={selectedType} size="h-8 w-12" />
        <span className="min-w-0 flex-1">
          <span className="block truncate font-medium">{selectedType.title}</span>
          {selectedType.tagline ? (
            <span className="block truncate text-xs text-muted-foreground">
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
      {/* Above the grid, not inside a popover header: with 17 cards the search
          is for the person who already knows the name, and it has to be the
          first thing their cursor lands on. */}
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

      {/* Chips, not tabs, and not the API's own categories.

          The catalogue has 11 of those for 17 types and 8 hold exactly one, so
          tabs would wrap to two rows to offer "education" → Moodle, alone.
          These are four buckets a person would reach for, built in
          type-categories.js, and a bucket with nothing in it is not rendered.

          Chips rather than a Tabs component because this filters a grid that is
          still fully searchable underneath — Tabs would claim these are
          separate panels, and the search box above would then be lying about
          how much it searches. */}
      {groups.length > 1 ? (
        <div className="flex flex-wrap gap-1.5">
          {/* Popular first, All last. The chips read as "here is the short
              answer, here are the kinds, and here is everything" — All beside
              Popular made the first two chips two ways of saying the same
              thing, and pushed the categories out to where nobody looks. */}
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
                    ? "border-primary bg-primary/10 text-primary"
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
        /* Container queries, not viewport ones: this grid sits in a column
           beside a 20rem summary panel, so the window width says nothing about
           how much room it has — the same reason the form's own fields are
           laid out with `@` rules. */
        <div className="grid grid-cols-1 gap-4 @xl:grid-cols-2 @3xl:grid-cols-3">
          {filtered.map((type) => {
            const disabled = !type.available;
            /*
             * A blocked card you can still CHOOSE.
             *
             * On a server with no engine and no Node, every card that needs one
             * is greyed and dead — so the screen that offers to install them
             * can never be reached, because reaching it means picking the very
             * application the server cannot host yet. Choosing is not creating:
             * it says "this is what I want", and what it needs follows.
             *
             * Only when everything blocking it is installable from here. The
             * form below offers to install exactly those, and Create stays
             * refused until the server says they are there — so the choice
             * leads somewhere rather than to a create the API rejects.
             */
            const choosable = !disabled || blockersAreFixable(type);
            /*
             * An unchoosable card is a `div`, not a disabled `button`.
             *
             * It cannot be chosen either way, and it still holds text a screen
             * reader has to reach — inside a disabled button that text is
             * skipped, and Playwright refuses to read it for the same reason.
             */
            const Card = choosable ? "button" : "div";
            const cardProps = choosable
              ? { type: "button", onClick: () => onChange(type.name) }
              : {};

            return (
              <Card
                key={type.name}
                {...cardProps}
                className={cn(
                  // Logo beside the words, not above them. Centred cards were
                  // four lines tall — mark, name, two of tagline — and
                  // seventeen of those ran to 870px before the form began. The
                  // same information laid out in a row is two lines and half
                  // the height, which is the whole complaint answered without
                  // hiding a single application.
                  "flex w-full items-center gap-3 rounded-xl border bg-muted/40 p-2.5 text-left transition-colors",
                  /*
                   * Dashed and faded means UNCHOOSABLE, not "something is
                   * missing".
                   *
                   * It was keyed on `disabled` at first, so a card that had
                   * just become clickable still looked exactly as dead as one
                   * that was not — same dashed border, same faded logo, same
                   * greyed name. Nothing on screen had changed, which is
                   * precisely what got reported. A control you can press has
                   * to look like one; the reason line underneath is what
                   * carries "but note this".
                   */
                  !choosable && "border-dashed",
                  choosable &&
                    "hover:border-primary/40 hover:bg-card hover:shadow-[0_1px_2px_rgb(0_0_0/0.04),0_2px_6px_rgb(0_0_0/0.05)] focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none",
                )}
              >
                {/* No "Popular" badge, though the data has the flag: six of
                    seventeen qualify, and a grid where a third of what you see
                    is labelled has labelled nothing. The order says it. */}
                <TypeTile type={type} dimmed={!choosable} size="h-8 w-10" />

                <span className={cn("min-w-0 flex-1")}>
                  {/* Every line here can be clipped by a narrow column or a
                      long locale, and a clipped line reads as a whole one.
                      TruncatedText measures and offers the rest on hover —
                      only when there IS a rest, so the grid does not pop a
                      bubble over text that plainly fits. */}
                  <TruncatedText
                    className={cn("text-sm font-medium", !choosable && "opacity-60")}
                  >
                    {type.title}
                  </TruncatedText>
                  {/* The tagline gives way to the reason on a blocked card: at
                      this size there is room for one line, and "needs MySQL or
                      MariaDB" is the thing worth reading on a card that cannot
                      be chosen. The bubble carries the server's whole sentence,
                      which says what is installed as well as what is wanted. */}
                  {disabled && type.unavailable_reason ? (
                    <span className="mt-0.5 flex items-center gap-1 text-xs leading-4 text-warning">
                      <TriangleAlert className="size-3 shrink-0" />
                      {/* The bubble carries one sentence PER blocker, stacked.
                          Joining them into a paragraph is how the second one
                          gets skimmed past, which is the whole complaint. */}
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
        /*
         * Two different dead ends, two different answers.
         *
         * "No results for wordpress" under an active Tools chip is a half
         * truth — it did match, in another category. Naming the chip is what
         * makes the empty grid make sense, and the button is the one click
         * back to the matches rather than asking someone to work out that a
         * filter they set three actions ago is the reason.
         */
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

      {/* Every way out this grid has, once.

          The cards say what they need; this says where to get it. Deduplicated
          by destination, because eight cards blocked on the same missing
          database engine have one answer between them, not eight — and
          repeating it on each of them is what made a 17-card grid taller than
          the entire form under it.

          Matched on `unavailable_code`, which the API sends precisely so this
          does not have to be inferred. It used to be read from `needs_database`
          plus the absence of an installable runtime, which reads a web-server
          refusal as a missing database. */}
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
