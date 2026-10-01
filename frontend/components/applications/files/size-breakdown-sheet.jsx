import { useCallback, useMemo } from "react";
import dynamic from "next/dynamic";
import { useTranslations } from "next-intl";
import { PieChart } from "lucide-react";
import { OTHER_TOKEN, SERIES_TOKENS, foldCategories } from "@/lib/charts/breakdown-option";
import { Skeleton } from "@/components/ui/skeleton";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";

// The chart library loads when the sheet's content first renders (Radix mounts it
// only on open), with a placeholder the donut's height so nothing jumps.
const SizeBreakdownDonut = dynamic(
  () => import("@/components/applications/files/size-breakdown-donut").then((m) => m.SizeBreakdownDonut),
  { ssr: false, loading: () => <Skeleton className="h-56 w-full rounded-lg" /> },
);

// Measured for the directory on screen, so the walk's cost is bounded.
export function SizeBreakdownSheet({ breakdown }) {
  const t = useTranslations("applications.files.breakdown");

  // Memoised so a null breakdown does not create a fresh [] each render.
  const categories = useMemo(() => breakdown?.categories ?? [], [breakdown]);
  const { slices } = useMemo(() => foldCategories(categories), [categories]);

  const label = useCallback((key) => t(`types.${key}`), [t]);

  // `getBreakdown` returns null on any failure; null is not measurable, never "no files".
  const unavailable = !breakdown || breakdown.available === false;
  const empty = breakdown?.available && categories.length === 0;

  // `available: false` means too big to walk; `null` means the request failed.
  const unavailableMessage = breakdown ? t("unavailable") : t("measureFailed");

  // Swatch token per category so the legend matches its segment; past the fifth,
  // all use the tail's grey, as the bar folds them into one segment.
  const swatch = (index) =>
    index < SERIES_TOKENS.length
      ? `var(--color-${SERIES_TOKENS[index]})`
      : `var(--color-${OTHER_TOKEN})`;

  return (
    <Sheet>
      <SheetTrigger asChild>
        <Button variant="outline" size="sm">
          <PieChart className="size-4" aria-hidden />
          {t("trigger")}
        </Button>
      </SheetTrigger>
      {/* Wider than the default `sm:max-w-sm`: the legend has four numeric columns. */}
      <SheetContent className="w-full sm:max-w-lg">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <PieChart className="size-4" aria-hidden />
            {t("title")}
          </SheetTitle>
          <SheetDescription>
            {unavailable
              ? unavailableMessage
              : empty
                ? t("empty")
                : t("subtitle", {
                    total: breakdown?.total_bytes_human ?? "",
                    files: breakdown?.file_count ?? 0,
                  })}
          </SheetDescription>
        </SheetHeader>

        {unavailable || empty ? null : (
          <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 pb-4">
            {/* The donut shows the overall shape; the table below gives exact sizes, which
                decide what to delete. */}
            <SizeBreakdownDonut
              slices={slices}
              label={label}
              dataTable={{
                caption: t("title"),
                columns: [t("columnType"), t("columnSize"), t("columnFiles")],
                rows: categories.map((category) => [
                  label(category.key),
                  category.bytes_human,
                  String(category.count),
                ]),
              }}
            />

            {/* Every category with visible values: two hues are under 3:1 here, so values
                cannot be hover-only. */}
            <table className="w-full text-sm">
              <thead className="border-b text-xs text-muted-foreground">
                <tr>
                  <th className="py-1.5 text-start font-normal" colSpan={2}>
                    {t("columnType")}
                  </th>
                  <th className="py-1.5 text-end font-normal">{t("columnSize")}</th>
                  <th className="py-1.5 text-end font-normal">{t("columnFiles")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {categories.map((category, index) => (
                  <tr key={category.key}>
                    {/* The swatch carries identity, never the text colour: light hues are illegible as
                        type. */}
                    <td className="w-4 py-1.5">
                      <span
                        className="block size-2 rounded-full"
                        style={{ backgroundColor: swatch(index) }}
                        aria-hidden
                      />
                    </td>
                    <td className="py-1.5 ps-2">{label(category.key)}</td>
                    <td className="py-1.5 text-end tabular-nums">
                      {category.bytes_human}
                    </td>
                    <td className="py-1.5 text-end tabular-nums text-muted-foreground">
                      {category.count}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {/* A partial total is stated, not passed off as complete. */}
            {breakdown?.truncated ? (
              <p className="text-xs text-muted-foreground">{t("truncated")}</p>
            ) : null}
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
}
