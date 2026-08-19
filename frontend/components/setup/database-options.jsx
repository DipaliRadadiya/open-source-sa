"use client";

import { useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2, Sparkles } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";

/**
 * Pick-one engine chooser (only the database component has options). An option
 * the panel can't install (MongoDB — needs its own apt repo) is shown disabled;
 * one already installed is marked so. The chosen option's own `action` endpoint
 * is what gets installed.
 *
 * No spinner in here, deliberately. This block only renders while its component
 * still needs installing — the moment its own install starts, the card replaces
 * it with the Installing pill. So a spinner on this button could never mean
 * "installing this"; it only ever meant "another component is installing", and
 * that is what it was wrongly saying. `disabled` + a reason says it truthfully.
 */
// What each engine is FOR, keyed on the API's own option value. Naming three
// engines and marking one "Recommended" tells you which button to press but
// never why — and the answer is different per project, not per server. Unknown
// engines simply fall back to their state, so the list stays API-driven.
const PURPOSE = {
  mysql: "purposeMysql",
  mariadb: "purposeMariadb",
  mongodb: "purposeMongodb",
};

export function DatabaseOptions({ options, failed = false, disabled = false, disabledReason, onInstall }) {
  const t = useTranslations("setup");
  const installable = useMemo(() => options.filter((o) => o.installable && !o.installed), [options]);
  const defaultValue = useMemo(
    () => installable.find((o) => o.recommended)?.value ?? installable[0]?.value ?? "",
    [installable],
  );
  const [selected, setSelected] = useState(defaultValue);
  const chosen = options.find((o) => o.value === selected);

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
        {options.map((option) => {
          // Not the `disabled` prop: an engine is off because of what it is, and
          // it says so in its own subtitle below — no tooltip needed. Picking an
          // engine while another component installs is harmless, so the lock
          // applies to the install button, not to choosing.
          const unavailable = option.installed || !option.installable;
          const active = option.value === selected;
          return (
            <button
              key={option.value}
              type="button"
              role="radio"
              aria-checked={active}
              disabled={unavailable}
              onClick={() => setSelected(option.value)}
              className={cn(
                // px-3 py-2.5: three of these plus an error box and a button
                // made the Database card 390px tall next to a 110px neighbour,
                // and the extra height was padding around two short words.
                "flex flex-col gap-1 rounded-xl border px-3 py-2.5 text-left text-sm transition-colors",
                // Selected reads as a tinted panel rather than an outline plus a
                // ring: the ring doubled the border weight and made the chosen
                // option the harshest thing in a card that already had a red
                // error box in it.
                active && !unavailable && "border-primary/60 bg-primary/5",
                !active && !unavailable && "hover:border-primary/40 hover:bg-muted/40",
                unavailable && "cursor-not-allowed opacity-60",
              )}
            >
              {/* One marker slot, and being *chosen* outranks being advised:
                  once you have picked something, which one you picked is the
                  fact you need back. The sparkle returns the moment it isn't
                  the selection. */}
              <span className="flex items-center justify-between gap-2 font-medium">
                {option.label}
                {active && !unavailable ? (
                  <CheckCircle2 className="size-4 shrink-0 text-primary" aria-hidden />
                ) : option.recommended ? (
                  <Sparkles className="size-3.5 shrink-0 text-primary" aria-hidden />
                ) : null}
              </span>
              {/* State wins when there is state to report — "already installed"
                  and "we can't install this" both change what the option means.
                  Otherwise the line is spent on what the engine is good for,
                  which is the question actually being asked. "Recommended" is
                  not repeated here: the sparkle above already says it. */}
              <span className="text-xs leading-snug text-muted-foreground">
                {option.installed
                  ? t("optionInstalled")
                  : !option.installable
                    ? t("optionUnavailable")
                    : PURPOSE[option.value]
                      ? t(PURPOSE[option.value])
                      : option.recommended
                        ? t("recommended")
                        : t("available")}
              </span>
            </button>
          );
        })}
      </div>

      {chosen?.action ? (
        <Button
          onClick={() => onInstall(chosen.action)}
          disabled={disabled}
          disabledReason={disabledReason}
          // Same treatment as the settings save buttons: a Button is shrink-0
          // and whitespace-nowrap, and this label carries an engine name that
          // grows in other locales — "Reintentar la instalación de MariaDB" ran
          // 44px off a 320px screen. It wraps rather than forcing the width.
          className="h-auto max-w-full py-2 text-center whitespace-normal"
        >
          {/* After a failure "Install MariaDB" reads as though the last attempt
              never happened. Naming it a retry is the difference between a
              fresh action and a second go at the one that just failed. */}
          {failed
            ? t("retryNamed", { name: chosen.label })
            : t("installNamed", { name: chosen.label })}
        </Button>
      ) : null}
    </div>
  );
}
