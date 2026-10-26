import { useFormatter, useTranslations } from "next-intl";
import { formatFileSize } from "@/lib/format/bytes";

// Hovering shows the exact byte count, which matches on every OS whatever its units.
export function FileSize({ bytes, className }) {
  const format = useFormatter();
  const t = useTranslations("applications.files");
  const label = formatFileSize(bytes, format);
  if (label === null) return null;
  return (
    <span className={className} title={bytes >= 1000 ? t("exactBytes", { bytes }) : undefined}>
      {label}
    </span>
  );
}
