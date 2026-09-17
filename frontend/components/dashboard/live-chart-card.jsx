import { useTranslations } from "next-intl";
import { ChartSpline } from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";

/**
 * Shared shell for the four live charts. They sit in a 2x2 grid, so the header
 * shape, chart height and "no samples yet" state have to be identical or the
 * grid reads as four unrelated cards.
 *
 * `badges` is the current reading — the number you came for — kept in the
 * header where it is legible, because reading a value off a moving line is
 * something nobody should have to do.
 */
/**
 * One current reading, in the shape both I/O cards use.
 *
 * The pills were tinted end to end — `bg-chart-2/10 text-chart-2` — which put
 * the number itself in a chart colour chosen to be legible as a 2px line, not
 * as text. And they led with a bare `↓`, which is a glyph rather than an icon,
 * says "down arrow" to a screen reader, and means nothing next to a Read/Write
 * chart where neither direction is down.
 *
 * So the colour moves to a dot — which is what actually maps the pill to its
 * line — the series name says which line in words, and the value gets full
 * foreground contrast. Both cards render the same shape, so a four-number disk
 * pill and a two-number network pill no longer read as different components.
 */
export function ChartPill({ dotClassName, label, value, note }) {
  return (
    /*
     * px-2.5 py-1.5 and a 2px dot. At px-2/py-1 with a 6px dot the four parts
     * of a Disk pill — dot, "Read", the rate, the op count — sat 6px apart
     * inside 8px of padding, so the pill had less air around its contents than
     * between them and read as a cramped strip of text rather than a chip.
     *
     * The gap between label and value stays tighter than the gap to the note,
     * because "Read 4.5 MB/s" is one reading and "210 IOPS" is a second.
     */
    <span className="inline-flex items-center gap-2 rounded-lg border bg-muted/40 px-2.5 py-1.5 text-xs">
      <span className={cn("size-2 shrink-0 rounded-full", dotClassName)} />
      <span className="flex items-center gap-1.5">
        <span className="text-muted-foreground">{label}</span>
        <span className="font-medium tabular-nums text-foreground">{value}</span>
      </span>
      {note ? <span className="tabular-nums text-muted-foreground">{note}</span> : null}
    </span>
  );
}

export function LiveChartCard({
  icon: Icon,
  title,
  description,
  badges,
  summary,
  ready,
  stale = false,
  // The live charts are waiting seconds for their second sample; the 24h ones
  // are waiting on a collector that runs every five minutes. "Waiting for
  // samples…" under a chart that will stay empty for the next five minutes
  // reads as broken.
  emptyTitle,
  emptyMessage,
  /*
   * Whether the empty state is a few seconds or possibly forever.
   *
   * The live cards hold the full plot height while they wait, deliberately:
   * they fill in after two polls and collapsing them would make the page jump
   * on every single load. The 24h cards may never get a sample on a server the
   * collector has not reached, so holding 288px of white there is 288px of
   * nothing, twice, which is what read as unfinished.
   */
  compactEmpty = false,
  children,
}) {
  const t = useTranslations("serverDashboard");

  return (
    // Dimmed on a dead poll, exactly like the stat cards. Without it a frozen
    // feed draws a perfectly flat line, which reads as a calm server rather
    // than as no server at all.
    // --card-spacing 16px → 20px gives the header and plot room to breathe.
    // Ring and shadow come from PANEL_CARD so every card on this page matches.
    <Card
      className={cn(
        "h-full transition-opacity [--card-spacing:--spacing(5)]",
        PANEL_CARD,
        stale && "opacity-60",
      )}
    >
      {/* Wraps, and the title keeps a real minimum width: the badges are
          shrink-0, so on a phone a wide pair of them (Disk I/O carries four
          numbers) squeezed the heading into a one-word-per-line column instead
          of dropping to its own row. */}
      {/*
       * Pills on their own row, always — not inline-until-they-wrap.
       *
       * Inline, Disk I/O's four numbers wrapped at the real content width and
       * Network's two did not, so the two plots side by side started 58px
       * apart. Reserving a second description line fixed the earlier version of
       * this and then stopped working the moment the pills got roomier, because
       * the wrap point moved. A row that is always there cannot move.
       *
       * It also gives the pills the width they were short of.
       */}
      <CardHeader className="flex flex-col items-stretch gap-3 space-y-0">
        <div className="min-w-0 space-y-1">
          {/* h3, not h2: these cards now sit inside a labelled section whose
              heading is the h2. text-lg still matches the Processes card, which
              is a real h2 because it is a direct child of the page.

              The icon gets the same chip the metric cards use, so the two rows
              of cards read as one family. A bare 16px glyph floating beside the
              text is the shadcn card header everyone ships. */}
          <CardTitle as="h3" className="flex items-center gap-2.5 text-lg font-semibold">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary ring-1 ring-inset ring-primary/20">
              <Icon className="size-4" />
            </span>
            {title}
          </CardTitle>
          {/* Still two lines reserved: with the pills on their own row the
              descriptions themselves differ in length, and "Inbound and
              outbound traffic, updating live." wraps at the real content width
              where "Read and write throughput, updating live." does not. */}
          <CardDescription className="min-h-10">{description}</CardDescription>
        </div>
        {badges ? <div className="flex flex-wrap gap-2">{badges}</div> : null}
      </CardHeader>
      {/* pt-0, not pt-3: Card already puts --card-spacing (16px) between header
          and content, so the extra padding made it 28px — off the 8pt rhythm
          and, in a 2x2 grid of these, 28px four times over. */}
      <CardContent className="pt-0">
        {/* A line needs two points. Until then say so, rather than drawing a
            single dot and calling it a trend. */}
        {ready ? (
          children
        ) : compactEmpty ? (
          /*
           * Solid, not dashed.
           *
           * A dashed rectangle is the universal "drop a file here / component
           * not built yet" placeholder, so on a finished card it reads as
           * scaffolding someone forgot to remove. Nothing here is missing from
           * the UI — the samples just have not been collected yet, which is a
           * calm fact and should look like one. A flat muted well with the same
           * radius as the plot it replaces, and an icon so the block has a
           * subject rather than being two lines floating in a box.
           */
          <div className="flex h-[130px] flex-col items-center justify-center gap-1 rounded-lg bg-muted/40 px-6 text-center">
            <span className="mb-1 flex size-8 items-center justify-center rounded-full bg-background text-muted-foreground shadow-e1">
              <ChartSpline className="size-4" />
            </span>
            <p className="text-sm font-medium">{emptyTitle ?? t("charts.noHistoryTitle")}</p>
            <p className="max-w-md text-xs text-pretty text-muted-foreground">
              {emptyMessage ?? t("charts.noHistory")}
            </p>
          </div>
        ) : (
          // Same height as the plot so nothing jumps when the line appears —
          // and it carries the current reading, because a card that says only
          // "waiting" is a card doing nothing for the first few seconds of
          // every single page load.
          <div className="flex h-72 flex-col items-center justify-center gap-2 px-6 text-center">
            {summary ? (
              <p className="text-lg font-semibold tabular-nums">{summary}</p>
            ) : null}
            <p className="text-sm text-muted-foreground">
              {emptyMessage ?? t("charts.waiting")}
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
