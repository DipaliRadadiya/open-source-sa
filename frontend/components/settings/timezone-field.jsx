import { useFormatter, useTranslations } from "next-intl";
import { Combobox } from "@/components/ui/combobox";

export function TimezoneField({ value, onChange, disabled, groups = [], id }) {
  const t = useTranslations("settings.server");
  const format = useFormatter();

  // Keep an unknown value selectable, or the field renders blank.
  const known = groups.some((group) =>
    group.zones.some((zone) => zone.value === value),
  );

  let localTime = null;
  try {
    localTime = format.dateTime(new Date(), {
      timeZone: value,
      dateStyle: "medium",
      timeStyle: "short",
    });
  } catch {
    localTime = null;
  }

  const options = groups.flatMap((group) =>
    group.zones.map((zone) => ({
      value: zone.value,
      label: zone.offset ? `${zone.label} (${zone.offset})` : zone.label,
      hint: group.region,
    })),
  );

  if (!known && value) options.unshift({ value, label: value });

  return (
    <div className="space-y-1.5">
      <Combobox
        id={id}
        options={options}
        value={value}
        onChange={onChange}
        disabled={disabled}
        className="w-full"
        searchPlaceholder={t("timezoneSearch")}
      />

      {localTime ? (
        <p
          className="text-xs whitespace-nowrap text-muted-foreground"
          suppressHydrationWarning
        >
          {t("localTime", { time: localTime })}
        </p>
      ) : null}
    </div>
  );
}
