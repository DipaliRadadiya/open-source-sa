"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { ServiceActions } from "@/components/services/service-actions";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";

/**
 * The services that need a person, listed rather than tabulated.
 *
 * These rows have nothing to put in a Memory, CPU or Start-on-boot column: a
 * failed install has no unit at all, and a crashed one has no measurements
 * worth comparing. Kept in the table they were four columns of em-dashes with
 * the one useful thing — how to fix it — pushed to the far right edge.
 *
 * Two kinds of trouble live here, because both need the same thing from the
 * reader (attention) while needing different things done:
 *
 *   install_failed  never installed. There is no unit to start, so the only
 *                   move is the setup page, where the install came from.
 *   crashed unit    installed and not running. Start/restart are real options
 *                   and its log exists, so both are offered.
 */
export function ServiceAttentionList({ services, phpVersions = [], canManage, busy, setRowBusy }) {
  const t = useTranslations("services");

  return (
    <ul className="divide-y rounded-xl border">
      {services.map((service) => {
        const failedInstall = service.state === "install_failed";

        // Say what is wrong in one line. A specific reason from the API is
        // always better than ours; `unknown` is its generic bucket, whose
        // sentence adds nothing to the badge and points at a reference this
        // screen does not have.
        const reason = failedInstall
          ? service.install_reason && service.install_reason !== "unknown"
            ? service.install_message
            : t("state.install_failed")
          : t("attention.unitFailed");

        return (
          <li
            key={service.key}
            className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
          >
            <div className="min-w-0 space-y-1">
              <div className="flex min-w-0 flex-wrap items-center gap-2">
                {/* text-sm, matching the running table on this same page. Left
                    at the default it was 16px — the size of the section heading
                    above it — so a row label competed with a section title. */}
                <p className="text-sm font-medium">{service.label}</p>
                <ServiceStatusBadge
                  status={service.status}
                  state={service.state}
                  busyAction={busy[service.key]}
                />
              </div>
              <p className="text-sm whitespace-normal wrap-anywhere text-muted-foreground">
                {reason}
              </p>
              {/* Only for a real unit: the file is what you would type into
                  systemctl, and a failed install has no file to name. */}
              {!failedInstall ? (
                <p className="truncate font-mono text-xs text-muted-foreground">{service.unit}</p>
              ) : null}
            </div>

            {/* The fix, at a fixed place on every row. A button rather than a
                text link — this is the primary action of the row, and the whole
                reason the row is in this section. */}
            <div className="flex shrink-0 items-center gap-1">
              {failedInstall ? (
                canManage ? (
                  <Button asChild variant="outline" size="sm">
                    <Link href="/setup">{t("attention.openSetup")}</Link>
                  </Button>
                ) : null
              ) : (
                <>
                  {/* No logs link of our own here: ServiceActions already
                      renders one when `log_keys` is non-empty, and adding a
                      second put two identical icons on the row. */}
                  <ServiceActions
                    service={service}
                    canManage={canManage}
                    phpVersion={phpVersions.find((v) => v.service === service.key)?.version}
                    onBusyChange={(action) => setRowBusy(service.key, action)}
                    reserveSlots={false}
                  />
                </>
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}
