import { useFormatter, useTranslations } from "next-intl";
import { formatFileSize } from "@/lib/format/bytes";
import { HoverHint } from "@/components/ui/hover-hint";

// Hovering shows the exact byte count, which matches on every OS whatever its units.
export function FileSize({ bytes, className }) {
  const format = useFormatter();
  const t = useTranslations("applications.files");
  const label = formatFileSize(bytes, format);
  if (label === null) return null;
  return (
    <HoverHint content={bytes >= 1000 ? t("exactBytes", { bytes }) : undefined}><span className={className}>
      {label}
    </span></HoverHint>
  );
}
