"use client";

import { useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { Sparkles } from "lucide-react";
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
export function DatabaseOptions({ options, disabled = false, disabledReason, onInstall }) {
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
                "flex flex-col gap-1 rounded-lg border p-3 text-left text-sm transition-colors",
                active && !unavailable && "border-primary bg-primary/[0.05] ring-1 ring-primary",
                !active && !unavailable && "hover:border-primary/40",
                unavailable && "cursor-not-allowed opacity-60",
              )}
            >
              <span className="flex items-center justify-between gap-2 font-medium">
                {option.label}
                {option.recommended ? <Sparkles className="size-3.5 text-primary" /> : null}
              </span>
              <span className="text-xs text-muted-foreground">
                {option.installed
                  ? t("optionInstalled")
                  : !option.installable
                    ? t("optionUnavailable")
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
        >
          {t("installNamed", { name: chosen.label })}
        </Button>
      ) : null}
    </div>
  );
}
