"use client";

import { useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { Loader2, Sparkles } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";

/**
 * Pick-one engine chooser (only the database component has options). An option
 * the panel can't install (MongoDB — needs its own apt repo) is shown disabled;
 * one already installed is marked so. The chosen option's own `action` endpoint
 * is what gets installed.
 */
export function DatabaseOptions({ options, busy = false, onInstall }) {
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
          const disabled = option.installed || !option.installable;
          const active = option.value === selected;
          return (
            <button
              key={option.value}
              type="button"
              role="radio"
              aria-checked={active}
              disabled={disabled}
              onClick={() => setSelected(option.value)}
              className={cn(
                "flex flex-col gap-1 rounded-lg border p-3 text-left text-sm transition-colors",
                active && !disabled && "border-primary bg-primary/[0.05] ring-1 ring-primary",
                !active && !disabled && "hover:border-primary/40",
                disabled && "cursor-not-allowed opacity-60",
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
        <Button onClick={() => onInstall(chosen.action)} disabled={busy}>
          {busy ? <Loader2 className="size-4 animate-spin" /> : null}
          {t("installNamed", { name: chosen.label })}
        </Button>
      ) : null}
    </div>
  );
}
