"use client";

import { useFormatter, useTranslations } from "next-intl";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

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

  return (
    <div className="space-y-1.5">
      <Select value={value} onValueChange={onChange} disabled={disabled}>
        {/* The Row renders a <label for> pointing at the FormItem id, and this
            is the one control that never received it — so a screen reader
            announced the timezone picker as its own value ("UTC (+00:00),
            combobox") and never said what it was for. */}
        <SelectTrigger id={id} className="w-full max-w-xs">
          <SelectValue />
        </SelectTrigger>
        <SelectContent className="max-h-72">
          {!known && value ? (
            <SelectItem value={value}>{value}</SelectItem>
          ) : null}
          {groups.map((group) => (
            <SelectGroup key={group.region}>
              <SelectLabel>{group.region}</SelectLabel>
              {group.zones.map((zone) => (
                <SelectItem key={zone.value} value={zone.value}>
                  {/* The offset is the thing people actually check a timezone
                      against, and the API recomputes it per request so it stays
                      right across daylight saving. */}
                  {zone.offset ? `${zone.label} (${zone.offset})` : zone.label}
                </SelectItem>
              ))}
            </SelectGroup>
          ))}
        </SelectContent>
      </Select>

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
