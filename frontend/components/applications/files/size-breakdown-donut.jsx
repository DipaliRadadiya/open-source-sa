import { useMemo } from "react";
import { EChart, useChartTokens } from "@/components/ui/echart";
import { OTHER_TOKEN, SERIES_TOKENS, breakdownOption } from "@/lib/charts/breakdown-option";

const TOKENS = [
  ...SERIES_TOKENS,
  OTHER_TOKEN,
  "card",
  "border",
  "popover",
  "popover-foreground",
];

/**
 * The Storage sheet's donut, in a file of its own so the chart library is only
 * fetched when someone opens the sheet — see size-breakdown-sheet.jsx.
 */
export function SizeBreakdownDonut({ slices, label, dataTable }) {
  const tokens = useChartTokens(TOKENS);
  const option = useMemo(
    () => (slices.length ? breakdownOption({ slices, tokens, label }) : null),
    [slices, tokens, label],
  );
  return <EChart option={option} height="h-56" dataTable={dataTable} />;
}
