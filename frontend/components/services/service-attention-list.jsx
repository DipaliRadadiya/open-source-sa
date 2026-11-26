import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { ServiceActions } from "@/components/services/service-actions";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { installHome } from "@/lib/services/install-home";

export function ServiceAttentionList({ services, phpVersions = [], canManage, busy, setRowBusy }) {
  const t = useTranslations("services");

  return (
    <ul className="divide-y rounded-xl border">
      {services.map((service) => {
        const failedInstall = service.state === "install_failed";
        const home = installHome(service.key);

        const reason = failedInstall
          ? service.install_reason && service.install_reason !== "unknown"
            ? service.install_message
            : t("state.install_failed")
          : (service.log_keys ?? []).length > 0
            ? t("attention.unitFailed")
            : t("attention.unitFailedNoLog");

        return (
          <li
            key={service.key}
            className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
          >
            <div className="min-w-0 space-y-1">
              <div className="flex min-w-0 flex-wrap items-center gap-2">
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
              {!failedInstall ? (
                <p className="truncate font-mono text-xs text-muted-foreground">{service.unit}</p>
              ) : null}
            </div>

            <div className="flex shrink-0 items-center gap-1">
              {failedInstall ? (
                canManage ? (
                  <Button asChild variant="outline" size="sm">
                    <Link href={home.href}>{t(`attention.${home.label}`)}</Link>
                  </Button>
                ) : null
              ) : (
                <>
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
