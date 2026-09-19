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
 * What this site type needs before it can be created, and one press to get it.
 *
 * The user's words: "detecting all the required dependencies upfront and
 * clearly informing the user that they are needed. Ideally, the installer could
 * list the required services and ask for permission to install them all at
 * once, rather than requiring the user to go back and forth."
 *
 * So: the list is the screen, not a sentence. Every missing service is a row
 * with its own state, because they finish at different times — the queue runs
 * one job at a time, and a second row sitting silently for four minutes is
 * indistinguishable from a stuck one. `queued` is a state you can see.
 *
 * It does NOT create the site. The reference mock had one button reading
 * "Install Nextcloud + dependencies", which cannot be honest here: the site
 * needs a domain and an admin password that are further down the form. This
 * installs the services while the form is filled in, and Create unlocks itself
 * when the last one lands.
 */

/*
 * The rows never re-order.
 *
 * They sorted by state at first, most-urgent up top, which looked sensible
 * written down and was wrong on screen: the moment the first install finished
 * it dropped to the bottom and the second row jumped up to take its place,
 * under a cursor that was resting there. Caught by driving it.
 *
 * The order is the order the services were listed in, and it holds for the
 * whole run — the badges carry the change, which is what badges are for.
 */

const ICONS = { node: Hexagon, php: FileCode2, database: Database };

/*
 * A square glyph per row, not the vendor's lockup.
 *
 * The brand marks were tried first and they are the wrong shape for this:
 * MySQL is 239×60, MariaDB 789×196, MongoDB 1102×278 — four-to-one lockups
 * that either hang out of a square slot or force a slot so wide it dwarfs the
 * text beside it. Neither reads as a list.
 *
 * So the icon says what KIND of thing the row is — a database, a runtime — and
 * the name beside it says which. That is what the reference this came from did
 * too, and it keeps every row identical whatever is in it.
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
          {/* The phase apt reported, never a percentage — the install is one
              call whose total is unknown until it ends, so a number would be
              invented. This matches what the PHP and Node pages already say. */}
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
     * Nothing this panel can install satisfies the application.
     *
     * PrestaShop wants PHP 7.2–8.1 and the install list offers 8.3 and 8.4:
     * the range is real, the shortfall is real, and there is no button that
     * would help. Saying so is the only honest option — an Install that cannot
     * work is the failure this whole screen exists to stop.
     */
    case "impossible":
      return (
        <ReasonTooltip reason={t("state.impossibleReason")}>
          <Badge variant="destructive" className="font-normal">
            <CircleAlert className="size-3" />
            {t("state.impossible")}
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

  /*
   * What the button would actually do if pressed.
   *
   * Counting only `missing` left it disabled after a failure — the one moment
   * the reader most wants to press it. A failed service is outstanding; it
   * just has an attempt behind it.
   */
  const outstanding = services.filter(
    (service) => service.state === "missing" || service.state === "failed",
  );

  const remaining = services.filter((service) => service.state !== "installed");
  /*
   * Nothing left that anything on this screen can do.
   *
   * Two ways to get here and they need different sentences: the reader lacks
   * the permission, or no version we can install fits. Both must stop the
   * footer promising an install that is not going to happen.
   */
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
              : // Counted from what is still OUTSTANDING, not from the list.
                // After the first of two lands, "needs 2 more services" is a
                // header arguing with the green tick underneath it.
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

      {/* One row per service. Not a sentence listing them: they have separate
          states and separate failures, and a sentence can hold neither. */}
      <ul className="divide-y">
        {services.map((service) => (
          <li key={service.key} className="flex items-center gap-3 px-4 py-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <ServiceMark service={service} />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium">{service.name}</span>
              <span className="block truncate text-xs text-muted-foreground">
                {service.state === "failed" && service.error
                  ? service.error
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
                  : // "the second one waits its turn" is a lie when there is
                    // only one. Most blocked types need exactly one thing.
                    t(outstanding.length > 1 ? "footerIdle" : "footerIdleOne")}
        </p>
        {/* Nothing here can be installed by this reader, so there is no button
            to grey out — a disabled control with no explanation is the thing
            the footer line is already saying better. */}
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
