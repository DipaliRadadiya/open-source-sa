import Link from "@/components/ui/app-link";

import { useFormatter, useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { ServiceActions } from "@/components/services/service-actions";
import { installHome } from "@/lib/services/install-home";
import { ServiceBootSwitch } from "@/components/services/service-boot-switch";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { CardList, CardListItem } from "@/components/data-table/card-list";

export function ServicesCards({ data, phpVersions = [], canManage, busy, setRowBusy }) {
  const t = useTranslations("services");
  const format = useFormatter();

  return (
    <CardList>
      {data.map((service) => {
        const php = phpVersions.find((v) => v.service === service.key);
        const usage = service.usage;
        const cpu = usage?.cpu_percent;

        return (
          <CardListItem
            key={service.key}
            // Same signal as the table's tinted row for a failed unit.
            className={cn(
              service.status === "failed" &&
                service.state === "installed" &&
                "border-destructive/30 bg-destructive/5",
            )}
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="truncate font-medium">{service.label}</p>
                {/* Same rule as the table's first column: with no unit to name
                    (installing or failed install), the reason takes its place. */}
                {service.state !== "installed" ? (
                  (service.install_reason && service.install_reason !== "unknown") ||
                  service.retryable ? (
                  <p className="text-xs whitespace-normal wrap-anywhere text-muted-foreground">
                    {service.install_reason && service.install_reason !== "unknown"
                      ? service.install_message
                      : null}
                    {service.retryable ? (
                      <>
                        {" "}
                        <Link
                          href={installHome(service.key).href}
                          className="font-medium text-foreground underline underline-offset-2"
                        >
                          {t(`state.${installHome(service.key).retryLabel}`)}
                        </Link>
                      </>
                    ) : null}
                  </p>
                  ) : null
                ) : (
                  <p className="truncate font-mono text-xs text-muted-foreground">
                    {service.unit}
                  </p>
                )}
              </div>
              <ServiceStatusBadge
                status={service.status}
                state={service.state}
                busyAction={busy[service.key]}
              />
            </div>

            {/* Both figures on one line. */}
            <div className="mt-3 flex items-center gap-4 border-t pt-3 text-xs">
              <Figure
                label={t("memoryShort")}
                value={usage?.memory_human}
                emptyLabel={t("notMeasured")}
              />
              <Figure
                label={t("cpuShort")}
                value={
                  cpu == null
                    ? null
                    : format.number(cpu / 100, {
                        style: "percent",
                        minimumFractionDigits: 1,
                        maximumFractionDigits: 1,
                      })
                }
                emptyLabel={t("notMeasured")}
              />
            </div>

            {/* Boot control and actions always on separate lines, so every
                card has the same shape. */}
            <div className="mt-3 space-y-3 border-t pt-3">
              {/* Labelled here: cards have no column header. */}
              <div className="flex items-center gap-2">
                <span className="whitespace-nowrap text-xs text-muted-foreground">
                  {t("columns.boot")}
                </span>
                <ServiceBootSwitch
                  service={service}
                  canManage={canManage}
                  onBusyChange={(action) => setRowBusy(service.key, action)}
                />
              </div>

              <ServiceActions
                service={service}
                canManage={canManage}
                phpVersion={php?.version}
                onBusyChange={(action) => setRowBusy(service.key, action)}
              />
            </div>
          </CardListItem>
        );
      })}
    </CardList>
  );
}

function Figure({ label, value, emptyLabel }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <span className="text-xs font-medium text-muted-foreground">
        {label}
      </span>
      {value == null || value === "" ? (
        // Words, not a dash: "not measured" differs from zero.
        <span className="text-muted-foreground">{emptyLabel}</span>
      ) : (
        <span className="font-medium tabular-nums">{value}</span>
      )}
    </div>
  );
}
