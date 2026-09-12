"use client";

import { useCallback, useMemo } from "react";
import { useTranslations } from "next-intl";
import { PieChart } from "lucide-react";
import { EChart, useChartTokens } from "@/components/ui/echart";
import {
  OTHER_TOKEN,
  SERIES_TOKENS,
  breakdownOption,
  foldCategories,
} from "@/lib/charts/breakdown-option";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";

const TOKENS = [
  ...SERIES_TOKENS,
  OTHER_TOKEN,
  "card",
  "border",
  "popover",
  "popover-foreground",
];

/**
 * What is using this folder's space — on demand, from the file manager toolbar.
 *
 * It used to be a card in a 340px sticky rail beside the listing, which cost the
 * listing that width: seven columns in what was left put Download and Copy
 * behind a horizontal scroll, and the rail was eventually held back to `2xl` so
 * both could fit. That still spends the widest screens' extra width on the
 * secondary thing. This is the same information behind a button, so the listing
 * keeps the whole row at every size.
 *
 * A sheet rather than a dialog: it is a reference panel you read while deciding
 * what to delete, and a sheet leaves the listing visible beside it.
 *
 * Measured for the directory on screen rather than the whole site, so browsing
 * into a folder bounds the cost of the walk that produced it — the site root is
 * the worst case and someone three folders down pays for three folders.
 */
export function SizeBreakdownSheet({ breakdown }) {
  const t = useTranslations("applications.files.breakdown");
  const tokens = useChartTokens(TOKENS);

  // Memoised so a null breakdown does not hand a fresh [] to every render
  // below it, which would rebuild the fold and the option each time.
  const categories = useMemo(() => breakdown?.categories ?? [], [breakdown]);
  const { slices } = useMemo(() => foldCategories(categories), [categories]);

  const label = useCallback((key) => t(`types.${key}`), [t]);

  const option = useMemo(
    () => (slices.length ? breakdownOption({ slices, tokens, label }) : null),
    [slices, tokens, label],
  );

  // Not measurable and empty are different answers. "This folder holds no
  // files" is a fact; "the walk did not finish" is an apology, and showing the
  // first when the second happened would be a confident lie about a disk.
  const unavailable = breakdown && breakdown.available === false;
  const empty = breakdown?.available && categories.length === 0;

  // Which token each category wears, so the legend's swatch matches the segment
  // it names. Past the fifth they are all the tail's grey — which is honest:
  // the bar folded them into one segment and the legend says which.
  const swatch = (index) =>
    index < SERIES_TOKENS.length
      ? `var(--color-${SERIES_TOKENS[index]})`
      : `var(--color-${OTHER_TOKEN})`;

  return (
    <Sheet>
      <SheetTrigger asChild>
        <Button variant="ghost" size="sm" className="text-muted-foreground">
          <PieChart className="size-4" aria-hidden />
          {t("trigger")}
        </Button>
      </SheetTrigger>
      {/* Wider than the default `sm:max-w-sm`: the legend is four columns of
          numbers, and the rail this replaces was already too narrow for them. */}
      <SheetContent className="w-full sm:max-w-lg">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <PieChart className="size-4" aria-hidden />
            {t("title")}
          </SheetTitle>
          <SheetDescription>
            {unavailable
              ? t("unavailable")
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
            {/* One bar, full width, no axes. It answers "roughly what shape is
                this" in 40px; the legend under it answers "how big exactly",
                which is the question that decides what to delete. */}
            <EChart
              option={option}
              height="h-10"
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

            {/* Every category, not just the six the bar can show — and the
                values are visible rather than hover-only, because two of the
                categorical hues sit under 3:1 against this surface and the
                palette check obligates a visible value somewhere.

                A real table, not a grid of divs: it is tabular data, and the
                header row is what tells a screen reader what the third number
                on each line means. */}
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
                    {/* The swatch carries identity, never the text colour: a
                        light categorical hue is illegible as type. */}
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

            {/* Said outright rather than left in a number that looks whole.
                A partial total read as complete is worse than no total. */}
            {breakdown?.truncated ? (
              <p className="text-xs text-muted-foreground">{t("truncated")}</p>
            ) : null}
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
}
