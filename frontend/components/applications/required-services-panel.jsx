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
      /*
       * Two different impossibilities, and the badge has to say which.
       *
       * "No version fits" is about a RANGE — PrestaShop wanting PHP 7.2-8.1
       * against an install list of 8.3 and 8.4. For an engine the vendor has
       * published nothing for, there is no range and no version; the label was
       * simply wrong, and the correct sentence was hidden in a tooltip nobody
       * on a touch screen can open.
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
          <li key={service.key} className="px-4 py-3">
            <div className="flex items-center gap-3">
              <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <ServiceMark service={service} />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">{service.name}</span>
                {/* Wraps, not truncates.
                
                    `truncate` was fine while every line here was "PrestaShop
                    needs PHP 7.2 – 8.1". The end-of-life sentence is longer
                    and its second half is the part that matters — it cut off
                    at "That line is no longer supported, but it is t…", which
                    leaves a warning with no resolution. A row that is one
                    pixel taller is cheaper than a half-read one. */}
                <span className="block text-xs text-pretty text-muted-foreground">
                  {service.state === "failed" && service.error
                    ? service.error
                    : /*
                       * The REQUIREMENT, when the application states one.
                       *
                       * The row's title is one exact build — "Node 24.12.0" —
                       * and on its own that reads as the thing n8n demands.
                       * It demands 24 or newer; 24.12.0 is merely the version
                       * this panel will install to satisfy it. Saying both
                       * stops the next person asking why their Node 24 is not
                       * good enough.
                       */
                      service.requirement
                      ? t(
                          /*
                           * A separate sentence when the only version this
                           * type can run on is one the language no longer
                           * patches. PrestaShop's whole window (7.2 – 8.1) is
                           * end-of-life; "this is what we will install" on its
                           * own hands someone an unsupported PHP without
                           * mentioning it, and the lifecycle was in the same
                           * payload all along.
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

            {/*
             * The reason, in the row, at full length.
             *
             * It was only in the badge's tooltip. A tooltip does not open on a
             * touch screen and nobody hovers a badge they have already read as
             * a label, so the one sentence that explains a dead end was
             * effectively unpublished — the same mistake as the engine card,
             * where the text was right and invisible.
             *
             * Only for this state. A row that is merely missing has a button
             * and needs no paragraph.
             */}
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
