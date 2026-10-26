import { useTranslations } from "next-intl";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

// Radix Select cannot hold null, which is how a schedule saved before the choice says "as before".
export const KEEP_DEFAULT = "default";

export function useKeepLabel() {
  const t = useTranslations("diskCleaner.keep");
  return (keep, recommended = null) => {
    if (keep == null) return t("defaultAsBefore");
    const label = keep === 0 ? t("deleteAll") : t("days", { count: keep });
    return keep === recommended ? t("recommended", { label }) : label;
  };
}

// `allowDefault`: only a schedule still on the old behaviour offers "Default (as before)".
// Never disabled: changing it only re-prices the list, so a viewer may try every choice.
export function KeepSelect({ id, value, onChange, choices, recommended, allowDefault = false, className }) {
  const label = useKeepLabel();

  return (
    <Select
      value={value == null ? KEEP_DEFAULT : String(value)}
      onValueChange={(next) => onChange(next === KEEP_DEFAULT ? null : Number(next))}
    >
      <SelectTrigger id={id} className={className}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent position="popper">
        {allowDefault ? <SelectItem value={KEEP_DEFAULT}>{label(null)}</SelectItem> : null}
        {choices.map((days) => (
          <SelectItem key={days} value={String(days)}>
            {label(days, recommended)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
