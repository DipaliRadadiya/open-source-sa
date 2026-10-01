import { useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";

// The chosen option's `action` endpoint is installed; once it starts the card replaces
// this block, so no spinner. One SQL engine per server: the choice is permanent.
const SQL_ENGINES = ["mysql", "mariadb"];

const PURPOSE = {
  mysql: "purposeMysql",
  mariadb: "purposeMariadb",
  mongodb: "purposeMongodb",
  postgresql: "purposePostgresql",
};

export function DatabaseOptions({ options, failed = false, disabled = false, disabledReason, onInstall }) {
  const t = useTranslations("setup");
  const installable = useMemo(() => options.filter((o) => o.installable && !o.installed), [options]);
  // The only installable engine, else the recommended one.
  const defaultValue =
    installable.length === 1
      ? installable[0].value
      : (installable.find((o) => o.recommended)?.value ?? null);
  const [selected, setSelected] = useState(defaultValue);
  const chosen = options.find((o) => o.value === selected);

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
        {options.map((option) => {
          // Not the `disabled` prop: the subtitle explains why. Choosing while another
          // component installs is harmless; the lock is on the install button.
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
                "flex flex-col gap-1 rounded-xl border px-3 py-2.5 text-left text-sm transition-colors",
                active && !unavailable && "border-primary/60 bg-primary/5",
                !active && !unavailable && "hover:border-primary/40 hover:bg-muted/40",
                unavailable && "cursor-not-allowed opacity-60",
              )}
            >
              {/* One marker slot: being chosen outranks being recommended. */}
              <span className="flex items-center justify-between gap-2 font-medium">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  {option.label}
                  {option.recommended && !unavailable ? (
                    <Badge variant="warning" className="font-normal">{t("recommended")}</Badge>
                  ) : null}
                </span>
                {active && !unavailable ? (
                  <CheckCircle2 className="size-4 shrink-0 text-primary" aria-hidden />
                ) : null}
              </span>
              {/* State (installed / not installable) wins; otherwise what the engine is
                  good for. */}
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

      {/* Same warning as the Databases page: the SQL engine choice is permanent. */}
      {chosen && SQL_ENGINES.includes(chosen.value) ? (
        <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed">
          {t("oneSqlOnly")}
        </p>
      ) : null}

      {chosen?.action ? (
        <Button
          onClick={() => onInstall(chosen.action)}
          disabled={disabled || selected === null}
          disabledReason={selected === null ? t("chooseEngineFirst") : disabledReason}
          // Wraps: the label carries an engine name that grows in other locales.
          className="h-auto max-w-full py-2 text-center whitespace-normal"
        >
          {/* After a failure, name it a retry. */}
          {failed
            ? t("retryNamed", { name: chosen.label })
            : t("installNamed", { name: chosen.label })}
        </Button>
      ) : null}
    </div>
  );
}
