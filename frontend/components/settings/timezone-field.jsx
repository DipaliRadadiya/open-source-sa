import { useFormatter, useTranslations } from "next-intl";
import { Combobox } from "@/components/ui/combobox";

/**
 * One field, one value — the same string the API takes.
 *
 * The list comes from `GET /timezones`, which is the exact list the save
 * validates against. It used to come from the browser, which spells Kolkata
 * "Calcutta" and has no `Etc/UTC` at all — so this server's own setting
 * rendered as an empty dropdown.
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

  /*
   * Searchable, because the list is ~400 long.
   *
   * A plain Select meant scrolling for a zone you already knew the name of —
   * the one list in the panel where typing is faster than looking. The region
   * becomes the option's hint rather than a group heading: Combobox has no
   * groups, and the hint is searched too, so "Asia", "Kolkata", "+05:30" and
   * the raw `Asia/Kolkata` all find the same row.
   */
  const options = groups.flatMap((group) =>
    group.zones.map((zone) => ({
      value: zone.value,
      // The offset is the thing people actually check a timezone against, and
      // the API recomputes it per request so it stays right across daylight
      // saving.
      label: zone.offset ? `${zone.label} (${zone.offset})` : zone.label,
      hint: group.region,
    })),
  );

  // A value the list does not contain still has to be selectable, or the field
  // renders blank and the user cannot even see what the server is set to.
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
