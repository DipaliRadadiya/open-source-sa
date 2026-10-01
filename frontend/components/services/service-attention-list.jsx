import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { ServiceActions } from "@/components/services/service-actions";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { installHome } from "@/lib/services/install-home";

/**
 * Services that need attention, listed rather than tabulated (they have no
 * usage figures worth columns). Two kinds:
 *
 *   install_failed  never installed; the only move is the screen that owns the
 *                   install (see lib/services/install-home.js).
 *   crashed unit    installed and not running; start/restart and its log are
 *                   offered.
 */
export function ServiceAttentionList({ services, phpVersions = [], canManage, busy, setRowBusy }) {
  const t = useTranslations("services");

  return (
    <ul className="divide-y rounded-xl border">
      {services.map((service) => {
        const failedInstall = service.state === "install_failed";
        const home = installHome(service.key);

        // The API's specific reason when it has one; its generic `unknown`
        // bucket adds nothing, so ours is used then.
        const reason = failedInstall
          ? service.install_reason && service.install_reason !== "unknown"
            ? service.install_message
            : t("state.install_failed")
          : (service.log_keys ?? []).length > 0
            ? t("attention.unitFailed")
            // No log the panel can open (MariaDB, PostgreSQL).
            : t("attention.unitFailedNoLog");

        return (
          <li
            key={service.key}
            className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
          >
            <div className="min-w-0 space-y-1">
              <div className="flex min-w-0 flex-wrap items-center gap-2">
                {/* text-sm, matching the running table on this page. */}
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
              {/* Only for a real unit: a failed install has no unit file. */}
              {!failedInstall ? (
                <p className="truncate font-mono text-xs text-muted-foreground">{service.unit}</p>
              ) : null}
            </div>

              {/* The fix, at a fixed place on every row. */}
            <div className="flex shrink-0 items-center gap-1">
              {failedInstall ? (
                canManage ? (
                  <Button asChild variant="outline" size="sm">
                    {/* The screen that owns this install, not always /setup —
                        see lib/services/install-home.js. */}
                    <Link href={home.href}>{t(`attention.${home.label}`)}</Link>
                  </Button>
                ) : null
              ) : (
                <>
                  {/* No separate logs link: ServiceActions renders one when
                      `log_keys` is non-empty. */}
                  <ServiceActions
                    service={service}
                    canManage={canManage}
                    phpVersion={phpVersions.find((v) => v.service === service.key)?.version}
                    onBusyChange={(action) => setRowBusy(service.key, action)}
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
