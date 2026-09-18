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
      {/*
       * Pills on the TITLE line, in the space beside the heading.
       *
       * They were on a row of their own, and the reason was real: inline
       * against the whole title BLOCK, Disk I/O's four numbers did not fit
       * beside a 262px block in a 560px card. But the block is only that wide
       * because the description sits inside it — beside the title TEXT there is
       * room in most locales at most widths.
       *
       * Where there is not, the pills take a line of their own rather than
       * squeezing the heading, and the plot below is bottom-anchored so the two
       * charts still start on the same line. Measured across en/de/ja at
       * 1024–1920: no heading ever breaks, and the two plots never differ.
       */}
      <CardHeader className="flex flex-col items-stretch gap-1 space-y-0">
        <div className="min-w-0 space-y-1">
          {/* h3, not h2: these cards now sit inside a labelled section whose
              heading is the h2. text-lg still matches the Processes card, which
              is a real h2 because it is a direct child of the page.

              The icon gets the same chip the metric cards use, so the two rows
              of cards read as one family. A bare 16px glyph floating beside the
              text is the shadcn card header everyone ships. */}
          {/*
           * justify-between, not ml-auto, and the icon + heading are one item.
           *
           * Two things have to be true at once: the pills sit at the right edge
           * beside the heading when there is room, and they read normally when
           * there is not. justify-content applies per LINE, so a pill group
           * that wraps is the only item on its line and lands at the start —
           * left-aligned under the heading, flowing the way text does. With
           * ml-auto it stayed pinned right, which on a phone drew each pill on
           * its own right-aligned row.
           *
           * No width threshold is involved, deliberately: the widest locale
           * needs 631px of header for Disk I/O and English needs 468px, so any
           * single breakpoint sized for Russian would push English onto two
           * lines on every screen we have.
           */}
          <CardTitle
            as="h3"
            className="flex flex-wrap items-center justify-between gap-x-2.5 gap-y-2 text-lg font-semibold"
          >
            <span className="flex shrink-0 items-center gap-2.5">
              <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary ring-1 ring-inset ring-primary/20">
                <Icon className="size-4" />
              </span>
              {/*
               * The heading is one phrase, not prose that may reflow: "Disk I/O"
               * broken after "Disk" is worse than a taller card. As a bare text
               * node it was an anonymous flex item — the only shrinkable thing
               * beside a shrink-0 pill group — so it was exactly the part that
               * gave way.
               */}
              <span className="whitespace-nowrap">{title}</span>
            </span>
            {badges ? <span className="flex flex-wrap gap-2">{badges}</span> : null}
          </CardTitle>
          {/* Still two lines reserved: with the pills on their own row the
              descriptions themselves differ in length, and "Inbound and
              outbound traffic, updating live." wraps at the real content width
              where "Read and write throughput, updating live." does not. */}
          <CardDescription className="min-h-10">{description}</CardDescription>
        </div>
      </CardHeader>
      {/* pt-0, not pt-3: Card already puts --card-spacing (16px) between header
          and content, so the extra padding made it 28px — off the 8pt rhythm
          and, in a 2x2 grid of these, 28px four times over. */}
      {/*
       * mt-auto: the plot is anchored to the BOTTOM of the card, not to the
       * bottom of the header.
       *
       * Both cards in a pair are the same height — the grid stretches them and
       * Card is h-full — so bottom-anchoring makes the two plots start on the
       * same line whatever their headers do. Without it, a header that is one
       * line taller pushes its whole chart down and the pair reads as two
       * unrelated cards; the slack now sits above the plot instead, where it is
       * just breathing room.
       */}
      <CardContent className="mt-auto pt-0">
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
