import { cn } from "@/lib/utils";
import { pct, usageTone } from "@/lib/metrics/usage-level";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/*
 * Measured-and-fine is green; nothing-to-measure is a hollow grey outline.
 *
 * Normal was `secondary` — a filled grey — which left the ladder relying on
 * filled-vs-hollow grey to separate "healthy" from "we could not measure it".
 * That is a distinction people miss, and it is the one that matters most: a
 * machine with no swap must not read as a machine with healthy swap. Seen on a
 * real panel where Disk said Unknown next to four Normals in the same colour.
 *
 * The cost is honest and accepted: at rest the row is five green pills saying
 * nothing new, and the amber Watch has to compete rather than being the only
 * colour present. Exception-only colour scans better; this trades a little of
 * that for a state ladder nobody has to squint at.
 */
const STATUS_VARIANT = {
  normal: "success",
  watch: "warning",
  high: "destructive",
  off: "outline",
  unknown: "outline",
};

/*
 * Only the icon chip and the bar carry the status color — the value stays
 * foreground so the numbers read cleanly.
 *
 * The chip gets an inset ring of its own colour. A flat 10%-alpha square on a
 * white card has no edge, so it read as a tinted gap rather than as a
 * container; the ring is what gives it one without adding a second weight.
 */
const TONE_STYLES = {
  primary: { chip: "bg-primary/15 text-primary ring-primary/25", bar: "bg-primary" },
  warning: { chip: "bg-warning/20 text-warning ring-warning/30", bar: "bg-warning" },
  destructive: {
    chip: "bg-destructive/15 text-destructive ring-destructive/25",
    bar: "bg-destructive",
  },
};

/*
 * One cool track for every card, whatever the fill is doing.
 *
 * Two things had to be true at once. The groove must not be a tint of its own
 * tone — that gave a High card a pink groove and a Normal card a blue one, five
 * bars in a row and no two the same component. And a flat neutral grey is a
 * dead channel next to a coloured fill; sampling the reference we were working
 * from, its track is #E8EEF7 — a blue-grey, cool, with the hue coming from the
 * brand rather than from the bar's state.
 *
 * So: the brand hue at a low alpha, fixed, for every tone. Red and amber fills
 * were checked against it too — at 91% and 96% the remaining sliver is barely
 * on screen anyway, and it does not fight the fill at lower values.
 */
const TRACK = "bg-primary/8";

/**
 * One measured number with a usage bar.
 *
 * Lives here rather than inside the dashboard because the disk figure on the
 * Disk Cleaner page is the same statistic in the same product — rebuilding it
 * by eye produced a card that drifted on every pass (different value size,
 * different icon treatment) until the two pages disagreed about what a
 * percentage looks like. Shared code can't drift.
 *
 * `status` is opt-in — `{ key, label }`, already translated by the caller. Two
 * of the three pages using this card measure a single fixed thing (one disk,
 * one engine) where a level word adds nothing, so defaulting it on would have
 * put a chip on screens that never asked for one.
 */
export function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  sub,
  percent,
  loading,
  hasSub,
  status = null,
}) {
  const tone = percent != null ? usageTone(percent) : "primary";
  const styles = TONE_STYLES[tone];

  return (
    // py-0 cancels Card's own vertical padding so CardContent controls it.
    // PANEL_CARD carries the ring and shadow — see lib/theme/card-chrome.js for
    // why the shadow has to be an arbitrary value to take effect at all.
    <Card className={cn("gap-0 overflow-hidden bg-gradient-to-t from-primary/5 to-card py-0", PANEL_CARD)}>
      {/*
       * A container, so the value row can answer to the CARD's width rather
       * than the window's.
       *
       * Measured across all eight locales: the value and its hint want up to
       * 209px side by side — "Wird gemessen… + 4 Kerne" in German, "80.0 GB
       * में से 37.6 GB" in Hindi — and at 1280 the five-column grid gives each
       * card about 190px. Every locale overflowed there, English included, by
       * 19px to 56px.
       *
       * The five cards are equal width, so a container query flips all of them
       * on the same tick. A plain `flex-wrap` would not: only the card whose
       * content is longest would wrap, and its bar and helper line would then
       * sit a row lower than the four beside it — the exact drift the comments
       * below spent two fixes removing.
       */}
      <CardContent className="@container/stat px-4 py-3.5">
        {/*
         * The label gets the whole row. The badge used to share it, and five
         * cards across a 1184px content column leaves about 82px for the label
         * once the icon and a badge have taken theirs — enough for "CPU" and
         * nothing else. Measured across all eight locales at 1280/1440/1600:
         * every language except English and Japanese clipped at least one
         * label, Hindi clipped four, and shortening the words one at a time
         * would have been chasing whichever locale was next.
         *
         * So the badge moves to the bottom row (below), where it sits in space
         * the helper line was not using and lines up across all five cards.
         */}
        <div className="flex min-h-8 items-center gap-2.5">
          {/*
           * The tile appears only when the status is NOT normal.
           *
           * Its colour was always meaningful — it turns amber then red as a
           * resource fills — but at rest all five cards wore an identical blue
           * square, so the one card that had gone amber had to out-shout four
           * decorations to be noticed. Healthy is the common case and should
           * be the quiet one: a plain icon at rest, the tinted chip only when
           * there is something to look at.
           *
           * `min-h-8` on the row keeps the five cards aligned either way.
           */}
          <span
            className={cn(
              "flex size-8 shrink-0 items-center justify-center rounded-lg",
              tone === "primary"
                ? "text-muted-foreground"
                : cn("ring-1 ring-inset", styles.chip),
            )}
          >
            <Icon className="size-4" />
          </span>
          <span className="min-w-0 truncate text-sm font-medium text-muted-foreground">{label}</span>
        </div>

        {/* The skeleton mirrors the loaded card line for line — value, hint, bar
            and sub. Skeletoning only the number let the other three appear from
            nowhere. */}
        {loading ? (
          <>
            <div className="mt-4 flex items-baseline justify-between gap-2">
              <Skeleton className="h-5 w-16" />
              <Skeleton className="h-4 w-24" />
            </div>
            {/* Same height and offset as the real bar, or the card grows by
                2px the moment it loads. */}
            <Skeleton className="mt-2.5 h-2 w-full rounded-full" />
            {hasSub ? <Skeleton className="mt-2.5 h-5 w-28" /> : null}
          </>
        ) : (
          <>
            {/* min-h so the row is the same height with or without a hint —
                the hint's line-height made cards that have one sit 2px lower,
                and the helper lines underneath then failed to line up across
                the row. */}
            <div className="mt-4 flex min-h-5 items-baseline justify-between gap-2 @max-[212px]/stat:flex-col @max-[212px]/stat:items-start @max-[212px]/stat:gap-y-1">
              <p className="text-xl font-semibold leading-none tracking-tight tabular-nums">
                {value}
              </p>
              {/* leading-none to match the value: on `items-baseline`, the
                  hint's half-leading pushed its baseline down and made cards
                  that have a hint 2px taller than those that don't, so the
                  helper lines underneath drifted out of line across the row. */}
              <span className="shrink-0 text-sm leading-none tabular-nums text-muted-foreground @max-[212px]/stat:shrink">
                {hint}
              </span>
            </div>

            {percent != null ? (
              <div
                role="progressbar"
                aria-label={label}
                aria-valuenow={Math.round(pct(percent))}
                aria-valuemin={0}
                aria-valuemax={100}
                className={cn("mt-2.5 h-2 w-full overflow-hidden rounded-full", TRACK)}
              >
                <div
                  className={cn(
                    "h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none",
                    styles.bar,
                  )}
                  style={{ width: `${pct(percent)}%` }}
                />
              </div>
            ) : (
              // A card with no percentage still reserves the bar's space so
              // Swap-is-Off does not sit 8px shorter than the four beside it.
              <div className="mt-2.5 h-2" />
            )}

            {/*
             * Helper line and status share the bottom row.
             *
             * min-h-5 keeps the row's height whether it holds one, both or
             * neither, so the five cards stay the same height — CPU has no
             * helper line, Swap-when-Off has no bar, and before this they were
             * three different heights that the grid was quietly stretching.
             */}
            {sub || status ? (
              <div className="mt-2.5 flex min-h-5 items-center justify-between gap-2">
                <p className="min-w-0 truncate text-xs tabular-nums text-muted-foreground">
                  {sub}
                </p>
                {status ? (
                  <Badge
                    variant={STATUS_VARIANT[status.key] ?? "outline"}
                    className="shrink-0 font-normal"
                  >
                    {status.label}
                  </Badge>
                ) : null}
              </div>
            ) : null}
          </>
        )}
      </CardContent>
    </Card>
  );
}
