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
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

const TOKENS = [
  ...SERIES_TOKENS,
  OTHER_TOKEN,
  "card",
  "border",
  "popover",
  "popover-foreground",
];

/**
 * What is using this folder's space, by kind of file.
 *
 * Measured for the directory on screen rather than the whole site, so browsing
 * into a folder bounds the cost of the walk that produced it — the site root is
 * the worst case and someone three folders down pays for three folders.
 *
 * The table is not a fallback for the chart. The donut answers "roughly what
 * shape is this", and the table answers "how big exactly", which is the
 * question that actually decides what to delete.
 */
export function SizeBreakdownCard({ breakdown }) {
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

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <PieChart className="size-4" />
          {t("title")}
        </CardTitle>
        <CardDescription>
          {unavailable
            ? t("unavailable")
            : empty
              ? t("empty")
              : t("subtitle", {
                  total: breakdown?.total_bytes_human ?? "",
                  files: breakdown?.file_count ?? 0,
                })}
        </CardDescription>
      </CardHeader>

      {unavailable || empty ? null : (
        // One column, always. This card sits in the file manager's side
        // column now, so the donut goes above its table rather than beside
        // it — a two-up split inside a 340px rail squeezes both.
        <CardContent className="space-y-4">
          <EChart
            option={option}
            height="h-52"
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

          <div>
            {/* Every category, not just the six the donut can show. */}
            <table className="w-full text-sm">
              <thead className="border-b text-xs text-muted-foreground">
                <tr>
                  <th className="py-1.5 text-start font-normal">{t("columnType")}</th>
                  <th className="py-1.5 text-end font-normal">{t("columnSize")}</th>
                  <th className="py-1.5 text-end font-normal">{t("columnFiles")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {categories.map((category) => (
                  <tr key={category.key}>
                    <td className="py-1.5">{label(category.key)}</td>
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
              <p className="pt-3 text-xs text-muted-foreground">{t("truncated")}</p>
            ) : null}
          </div>
        </CardContent>
      )}
    </Card>
  );
}
