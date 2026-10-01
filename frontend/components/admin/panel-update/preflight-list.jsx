import { useTranslations, useFormatter } from "next-intl";
import { Check, CircleX, TriangleAlert } from "lucide-react";
import { isUnknownDetail, megabytes, parseSizeDetail } from "@/lib/admin/preflight-detail";

/**
 * The preflight gate: each check must pass before an update can start.
 *
 * Three shapes, decided by the data:
 * - failing: a full-width red row, the only actionable item on the page;
 * - passing with a MEASUREMENT: a tile leading with the figure;
 * - everything else: a compact row.
 *
 * An ADVISORY check never gets a red row: it does not gate the update (see
 * UpdatePreflight::run()). It keeps its tile and shows a muted warning instead
 * of a tick when short, since a green tick over a low figure would mislead.
 *
 * Grids are `auto-fit`, never a fixed column count, so the last row fills.
 * Unknown keys fall back to the raw key. `clean_working_tree` fails closed when
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
  // A tile label is a heading ("Disk space"); falls back to the full name.
  const shortName = (key) => (t.has(`preflightShort.${key}`) ? t(`preflightShort.${key}`) : name(key));

  const size = (mb) => {
    const unit = megabytes(mb);
    if (!unit) return null;
    return `${format.number(unit.value, { maximumFractionDigits: unit.maximumFractionDigits })} ${unit.unit}`;
  };

  // A null parse (or backend "unknown") means no figure to lead with.
  const withSize = checks.map((c) => ({ ...c, measured: parseSizeDetail(c.detail) }));
  const failed = withSize.filter((c) => !c.passed && !c.advisory);
  const tiles = withSize.filter((c) => (c.passed || c.advisory) && c.measured);
  const rows = withSize.filter((c) => (c.passed || c.advisory) && !c.measured);
  // Counts only gating checks, so an advisory shortfall does not read as "4 of 5
  // ready" beside an update that can start.
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
            // Figure and caption sit at opposite ends of each row.
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
                  {/* "recommended", not "needed", when the shortfall blocks nothing; the caption
                      must agree with the button. */}
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
            // No `shrink-0` on the detail: the backend's detail text can grow (e.g.
            // "+ 0MB swap") and must wrap instead of overflowing into the next card.
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
