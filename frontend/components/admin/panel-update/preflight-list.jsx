import { useTranslations, useFormatter } from "next-intl";
import { Check, CircleX, TriangleAlert } from "lucide-react";
import { isUnknownDetail, megabytes, parseSizeDetail } from "@/lib/admin/preflight-detail";

/**
 * The preflight gate: each check must pass before an update can start.
 *
 * Three shapes, decided by the data rather than by a list of keys:
 *
 * - anything failing gets a full-width red row, because it is the only thing on
 *   the page you can act on. In a grid the one blocking check landed in
 *   whichever cell it fell into, tinted across half a row with a hole beside it;
 * - a passing check that reports a MEASUREMENT becomes a tile with the figure,
 *   because "have I got room" is answered by a number, not a tick;
 * - everything else is a compact row.
 *
 * An ADVISORY check is never one of the red rows, however it reads: it does not
 * gate the update (see UpdatePreflight::run()), so presenting it as the thing
 * standing in your way would be a lie about a button that works. It keeps its
 * tile and shows a muted warning instead of a tick when short — the figure is
 * the first thing to look at if a build is later killed, and a green tick over
 * 300 MB would be a worse lie than no check at all.
 *
 * The grids are `auto-fit`, never a fixed column count: with three checks or
 * five, the last row stretches to fill instead of leaving an empty cell.
 *
 * Known keys get a friendly localized name; an unknown future key falls back to
 * the raw key so the list never breaks. `clean_working_tree` fails closed when
 * the tree state is unknown (a forced checkout would discard uncommitted work).
 */
const FILL = "grid gap-3 grid-cols-[repeat(auto-fit,minmax(14rem,1fr))]";

function StatusIcon({ passed, advisory = false }) {
  if (passed) return <Check className="size-4 shrink-0 text-success" aria-hidden />;
  return advisory ? (
    <TriangleAlert className="size-4 shrink-0 text-muted-foreground" aria-hidden />
  ) : (
    <CircleX className="size-4 shrink-0 text-destructive" aria-hidden />
  );
}

export function PreflightList({ checks }) {
  const t = useTranslations("panelUpdate");
  const format = useFormatter();
  if (!checks.length) return null;

  const name = (key) => (t.has(`preflight.${key}`) ? t(`preflight.${key}`) : key);
  // A tile is a column heading, not a sentence: "Disk space", not "Enough free
  // disk space". Falls back to the full name for a check with no short form.
  const shortName = (key) => (t.has(`preflightShort.${key}`) ? t(`preflightShort.${key}`) : name(key));

  const size = (mb) => {
    const unit = megabytes(mb);
    if (!unit) return null;
    return `${format.number(unit.value, { maximumFractionDigits: unit.maximumFractionDigits })} ${unit.unit}`;
  };

  // A measurement makes a tile; the parser returning null (or the backend
  // saying "unknown") means there is no figure to lead with.
  const withSize = checks.map((c) => ({ ...c, measured: parseSizeDetail(c.detail) }));
  const failed = withSize.filter((c) => !c.passed && !c.advisory);
  const tiles = withSize.filter((c) => (c.passed || c.advisory) && c.measured);
  const rows = withSize.filter((c) => (c.passed || c.advisory) && !c.measured);
  // Counts what gates the button, so an advisory check short on memory does not
  // read as "4 of 5 ready" next to an update that starts perfectly well.
  const gating = checks.filter((c) => !c.advisory);
  const passedCount = gating.filter((c) => c.passed).length;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <p className="text-sm font-medium">{t("preflightTitle")}</p>
        <p className="text-sm tabular-nums text-muted-foreground">
          {t("readyCount", { passed: passedCount, total: gating.length })}
        </p>
      </div>

      {failed.length ? (
        <ul className="space-y-2">
          {failed.map((c) => (
            <li
              key={c.key}
              className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-destructive/30 bg-destructive/[0.05] px-4 py-3"
            >
              <StatusIcon passed={false} />
              <p className="min-w-0 flex-1 text-sm font-medium text-destructive">{name(c.key)}</p>
              {c.measured ? (
                <p className="shrink-0 font-mono text-sm text-destructive">
                  {size(c.measured.haveMb)}
                </p>
              ) : isUnknownDetail(c.detail) ? (
                <p className="shrink-0 text-xs text-muted-foreground">{t("detailUnknown")}</p>
              ) : c.detail ? (
                <p className="shrink-0 font-mono text-xs break-words text-muted-foreground">
                  {c.detail}
                </p>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      {tiles.length ? (
        <div className={FILL}>
          {tiles.map((c) => (
            // Both rows run edge to edge: the figure and what it means sit at
            // opposite ends rather than stacked down the left with the right
            // half of the tile empty.
            <div key={c.key} className="rounded-xl border bg-muted/25 px-4 py-3">
              <div className="flex items-center gap-2">
                <p className="min-w-0 flex-1 truncate text-xs font-medium tracking-wide text-muted-foreground uppercase">
                  {shortName(c.key)}
                </p>
                <StatusIcon passed={c.passed} advisory={c.advisory} />
              </div>
              <div className="mt-1.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                <p className="font-mono text-base leading-none font-semibold tracking-tight">
                  {size(c.measured.haveMb)}
                </p>
                <p className="text-xs text-muted-foreground">
                  {/* "recommended", not "needed", when the shortfall does not
                      stop anything — the caption has to agree with the button. */}
                  {c.passed
                    ? t(c.measured.kind === "free" ? "captionFree" : "captionAvailable", {
                        need: size(c.measured.needMb),
                      })
                    : t("captionRecommended", { need: size(c.measured.needMb) })}
                </p>
              </div>
            </div>
          ))}
        </div>
      ) : null}

      {rows.length ? (
        <ul className={FILL}>
          {rows.map((c) => (
            // `shrink-0` on the detail meant a sentence the parser did not
            // recognise could not shrink OR wrap, so it ran straight out of the
            // card and printed over the one beside it. The backend adding a
            // term to a detail string is enough to cause that — it did, with
            // "+ 0MB swap" — so the row wraps rather than trusting the text to
            // be short.
            <li
              key={c.key}
              className="flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-xl border px-4 py-3 text-sm"
            >
              <StatusIcon passed={c.passed} advisory={c.advisory} />
              <span className="min-w-0 flex-1">{name(c.key)}</span>
              {isUnknownDetail(c.detail) ? null : c.detail ? (
                <span className="min-w-0 basis-full font-mono text-xs break-words text-muted-foreground sm:ml-auto sm:basis-auto">
                  {c.detail}
                </span>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
