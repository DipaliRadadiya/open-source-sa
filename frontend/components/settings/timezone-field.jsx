import { useFormatter, useTranslations } from "next-intl";
import { Combobox } from "@/components/ui/combobox";

/**
 * One field, one value: the same string the API takes. The list comes from
 * `GET /timezones`, the exact list the save validates against (the browser's
 * list differs, e.g. "Calcutta", no `Etc/UTC`).
 */
export function TimezoneField({ value, onChange, disabled, groups = [], id }) {
  const t = useTranslations("settings.server");
  const format = useFormatter();

  // A value the list doesn't contain still has to be selectable, or the field
  // renders blank and the user can't even see what the server is set to.
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

  // Searchable (~400 zones). Combobox has no groups, so the region becomes the
  // option's hint, which is searched too.
  const options = groups.flatMap((group) =>
    group.zones.map((zone) => ({
      value: zone.value,
      // The API recomputes the offset per request, so it stays right across DST.
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
        className="w-full max-w-xs"
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
