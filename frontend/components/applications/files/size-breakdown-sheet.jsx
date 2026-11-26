import { useCallback, useMemo } from "react";
import dynamic from "next/dynamic";
import { useFormatter, useTranslations } from "next-intl";
import { PieChart } from "lucide-react";
import { OTHER_TOKEN, SERIES_TOKENS, foldCategories } from "@/lib/charts/breakdown-option";
import { formatFileSize } from "@/lib/format/bytes";
import { FileSize } from "@/components/applications/files/file-size";
import { SkeletonCard } from "@/components/ui/skeleton";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";

// Radix only mounts the content on open, so the chart library loads then.
const SizeBreakdownDonut = dynamic(
  () => import("@/components/applications/files/size-breakdown-donut").then((m) => m.SizeBreakdownDonut),
  { ssr: false, loading: () => <SkeletonCard className="h-56 w-full" /> },
);

export function SizeBreakdownSheet({ breakdown }) {
  const t = useTranslations("applications.files.breakdown");
  const format = useFormatter();

  const categories = useMemo(() => breakdown?.categories ?? [], [breakdown]);
  const { slices } = useMemo(() => foldCategories(categories), [categories]);

  const label = useCallback((key) => t(`types.${key}`), [t]);

  const unavailable = !breakdown || breakdown.available === false;
  const empty = breakdown?.available && categories.length === 0;

  const unavailableMessage = breakdown ? t("unavailable") : t("measureFailed");

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
                    total: formatFileSize(breakdown?.total_bytes ?? 0, format),
                    files: breakdown?.file_count ?? 0,
                  })}
          </SheetDescription>
        </SheetHeader>

        {unavailable || empty ? null : (
          <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 pb-4">
            <SizeBreakdownDonut
              slices={slices}
              label={label}
              dataTable={{
                caption: t("title"),
                columns: [t("columnType"), t("columnSize"), t("columnFiles")],
                rows: categories.map((category) => [
                  label(category.key),
                  formatFileSize(category.bytes, format),
                  String(category.count),
                ]),
              }}
            />

            <table className="w-full text-sm">
              <thead className="border-b text-xs text-muted-foreground">
                <tr>
                  <th data-slot="table-head" className="py-1.5 text-start font-normal" colSpan={2}>
                    {t("columnType")}
                  </th>
                  <th data-slot="table-head" className="py-1.5 text-end font-normal">{t("columnSize")}</th>
                  <th data-slot="table-head" className="py-1.5 text-end font-normal">{t("columnFiles")}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {categories.map((category, index) => (
                  <tr key={category.key}>
                    <td className="w-4 py-1.5">
                      <span
                        className="block size-2 rounded-full"
                        style={{ backgroundColor: swatch(index) }}
                        aria-hidden
                      />
                    </td>
                    <td className="py-1.5 ps-2">{label(category.key)}</td>
                    <td className="py-1.5 text-end tabular-nums">
                      <FileSize bytes={category.bytes} />
                    </td>
                    <td className="py-1.5 text-end tabular-nums text-muted-foreground">
                      {category.count}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {breakdown?.truncated ? (
              <p className="text-xs text-muted-foreground">{t("truncated")}</p>
            ) : null}
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
}
