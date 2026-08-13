"use client";

import { useFormatter, useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { ServiceActions } from "@/components/services/service-actions";
import { ServiceBootSwitch } from "@/components/services/service-boot-switch";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { CardList, CardListItem } from "@/components/data-table/card-list";

/**
 * The same services as cards, for screens too narrow for six columns.
 *
 * The table does work sideways on a phone, but the action buttons sit off the
 * right edge with nothing to suggest a swipe — so the one thing you came to do
 * at 2am is the one thing you can't see. A card puts every part of a service in
 * one column: what it is, how it's doing, and what you can do about it.
 */
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
            // Same signal as the table's tinted row: a failed unit is why you
            // opened the page.
            className={cn(service.status === "failed" && "border-destructive/30 bg-destructive/5")}
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="truncate font-medium">{service.label}</p>
                <p className="truncate font-mono text-xs text-muted-foreground">
                  {service.unit}
                </p>
              </div>
              <ServiceStatusBadge status={service.status} busyAction={busy[service.key]} />
            </div>

            {/* Both figures on one line: on a narrow card they're a pair to
                glance at, not a column to scan. */}
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

            {/* Boot control and actions on separate lines, always — not
                "whenever they don't fit". Letting them share a line when there
                was room meant the layout changed per service: cards with fewer
                icons sat inline, crowded ones wrapped, and the wrapped ones
                didn't line up with anything. Same shape on every card beats one
                saved line on some of them. */}
            <div className="mt-3 space-y-3 border-t pt-3">
              {/* The switch needs its label here — there's no column header on a
                  card to say what it does. */}
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
                reserveSlots={false}
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
      <span className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </span>
      {value == null || value === "" ? (
        // Spelled out rather than an em dash: a card has room for the words, and
        // "not measured" is a different fact from zero.
        <span className="text-muted-foreground">{emptyLabel}</span>
      ) : (
        <span className="font-medium tabular-nums">{value}</span>
      )}
    </div>
  );
}
