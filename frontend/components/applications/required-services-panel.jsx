import {
  CircleAlert,
  CircleCheck,
  Clock,
  Database,
  FileCode2,
  Hexagon,
  Info,
  Loader2,
  Zap,
} from "lucide-react";
import { useTranslations } from "next-intl";

import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

/**
 * What this site type needs before it can be created, with one press to
 * install it all. Each missing service is a row with its own state, since the
 * queue runs one job at a time and `queued` must be visible.
 *
 * It does not create the site (domain and admin password come later in the
 * form); it installs services while the form is filled in, and Create unlocks
 * when the last one lands.
 */

/*
 * Rows never re-order: sorting by state made rows jump under the cursor as
 * installs finished. The badges carry the change.
 */

const ICONS = { node: Hexagon, php: FileCode2, database: Database };

/*
 * A square glyph per kind (database, runtime), not the vendor's wide lockup,
 * so every row has the same shape; the name says which.
 */
function ServiceMark({ service }) {
  const Icon = ICONS[service.kind] ?? Database;
  return <Icon className="size-5" aria-hidden />;
}

function StateBadge({ service }) {
  const t = useTranslations("applications.requiredServices");

  switch (service.state) {
    case "installed":
      return (
        <Badge variant="success" className="font-normal">
          <CircleCheck className="size-3" />
          {t("state.installed")}
        </Badge>
      );
    case "installing":
      return (
        <Badge variant="warning" className="font-normal">
          <Loader2 className="size-3 animate-spin" />
          {/* The phase apt reported, never a percentage (the total is unknown), as on
              the PHP and Node pages. */}
          {service.step ? t(`step.${service.step}`) : t("state.installing")}
        </Badge>
      );
    case "queued":
      return (
        <Badge variant="outline" className="font-normal text-muted-foreground">
          <Clock className="size-3" />
          {t("state.queued")}
        </Badge>
      );
    case "failed":
      return (
        <Badge variant="destructive" className="font-normal">
          <CircleAlert className="size-3" />
          {t("state.failed")}
        </Badge>
      );
    case "denied":
      return (
        <ReasonTooltip reason={t("state.deniedReason")}>
          <Badge variant="outline" className="font-normal text-muted-foreground">
            {t("state.denied")}
          </Badge>
        </ReasonTooltip>
      );
    /*
     * Nothing this panel can install satisfies the application (e.g. PrestaShop
     * needs PHP 7.2–8.1 and only 8.3/8.4 are installable). No Install button.
     */
    case "impossible":
      /*
       * The badge distinguishes "no version fits" (a range) from an engine with no
       * published versions at all.
       */
      return (
        <ReasonTooltip reason={service.reason ?? t("state.impossibleReason")}>
          <Badge variant="destructive" className="font-normal">
            <CircleAlert className="size-3" />
            {service.reason ? t("state.unavailableHere") : t("state.impossible")}
          </Badge>
        </ReasonTooltip>
      );
    default:
      return (
        <Badge variant="outline" className="font-normal text-muted-foreground">
          {t("state.missing")}
        </Badge>
      );
  }
}

export function RequiredServicesPanel({
  typeTitle,
  services = [],
  onInstall,
  onRetry,
  busy = false,
  className,
}) {
  const t = useTranslations("applications.requiredServices");

  if (services.length === 0) return null;

  const failed = services.filter((service) => service.state === "failed");
  const denied = services.filter((service) => service.state === "denied");
  const working = services.some(
    (service) => service.state === "installing" || service.state === "queued",
  );
  const settled = services.every((service) => service.state === "installed");

  // Failed services count as outstanding, so the button works for a retry.
  const outstanding = services.filter(
    (service) => service.state === "missing" || service.state === "failed",
  );

  const remaining = services.filter((service) => service.state !== "installed");
  // Nothing on this screen can help: no permission, or no installable version.
  // Either way, the footer must not promise an install.
  const stuck = services.filter((service) => service.state === "impossible");
  const blocked = outstanding.length === 0 && (denied.length > 0 || stuck.length > 0);

  return (
    <div
      className={cn(
        "overflow-hidden rounded-xl border",
        settled ? "border-success/30 bg-success/5" : "bg-card",
        className,
      )}
      data-state={settled ? "installed" : working ? "working" : failed.length ? "failed" : "missing"}
    >
      <div className="flex gap-3 border-b bg-muted/30 p-4">
        <span
          className={cn(
            "flex size-8 shrink-0 items-center justify-center rounded-lg",
            settled ? "bg-success/10 text-success" : "bg-primary/10 text-primary",
          )}
        >
          {settled ? <CircleCheck className="size-4" /> : <Info className="size-4" />}
        </span>
        <div className="min-w-0 space-y-0.5">
          <p className="text-sm font-medium">
            {settled
              ? t("headingReady", { app: typeTitle })
              : // Counts what remains, so the header agrees with finished rows.
                t("heading", { app: typeTitle, count: remaining.length })}
          </p>
          <p className="text-sm text-muted-foreground">
            {settled
              ? t("subReady")
              : blocked
                ? denied.length
                  ? t("subDenied")
                  : t("subImpossible")
                : t("sub")}
          </p>
        </div>
      </div>

      {/* One row per service: each has its own state and failure. */}
      <ul className="divide-y">
        {services.map((service) => (
          <li key={service.key} className="px-4 py-3">
            <div className="flex items-center gap-3">
              <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <ServiceMark service={service} />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">{service.name}</span>
                {/* Wraps, not truncates: the end of the end-of-life sentence is the part
                    that matters. */}
                <span className="block text-xs text-pretty text-muted-foreground">
                  {service.state === "failed" && service.error
                    ? service.error
                    : /*
                       * The requirement when stated: the title is the exact
                       * build to install ("Node 24.12.0"), not what the app
                       * demands ("24 or newer").
                       */
                      service.requirement
                      ? t(
                          /*
                           * A separate sentence when the only versions this
                           * type runs on are end-of-life, so an unsupported PHP
                           * is never installed without saying so.
                           */
                          service.eol ? `needsEol.${service.kind}` : `needs.${service.kind}`,
                          { app: typeTitle, requirement: service.requirement },
                        )
                      : t(`purpose.${service.kind}`, { app: typeTitle })}
                </span>
              </span>
              <span className="flex shrink-0 items-center gap-2">
                <StateBadge service={service} />
                {service.state === "failed" ? (
                  <Button size="sm" variant="outline" onClick={() => onRetry?.(service)}>
                    {t("retry")}
                  </Button>
                ) : null}
              </span>
            </div>

            {/* The reason in full, in the row: tooltips do not open on touch. Only for
                this state; a missing row has a button instead. */}
            {service.reason ? (
              <p className="mt-2 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-2.5 text-xs leading-relaxed text-destructive">
                <CircleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                <span>{service.reason}</span>
              </p>
            ) : null}
          </li>
        ))}
      </ul>

      <div className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/20 px-4 py-3">
        <p className="min-w-48 flex-1 text-xs text-muted-foreground">
          {settled
            ? t("footerReady")
            : working
              ? t("footerWorking")
              : blocked
                ? denied.length
                  ? t("footerDenied")
                  : t("footerImpossible")
                : failed.length
                  ? t("footerFailed", { count: failed.length })
                  : // Singular copy when only one service is outstanding.
                    t(outstanding.length > 1 ? "footerIdle" : "footerIdleOne")}
        </p>
        {/* No button when this reader cannot install anything; the footer explains. */}
        {settled || outstanding.length === 0 ? null : (
          <Button size="sm" onClick={onInstall} disabled={busy || working}>
            {working ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <Zap className="size-4" />
            )}
            {failed.length ? t("installRetryAll") : t("install")}
          </Button>
        )}
      </div>
    </div>
  );
}
