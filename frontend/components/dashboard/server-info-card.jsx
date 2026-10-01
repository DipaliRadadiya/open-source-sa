import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { Server, Network, Cpu, Terminal, CircleCheck, CircleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import { Badge } from "@/components/ui/badge";
import { CopyButton } from "@/components/ui/copy-button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Card, CardContent, CardFooter } from "@/components/ui/card";
import { SiteAttention } from "@/components/dashboard/site-attention";
import { EngineLogo } from "@/components/databases/engine-logo";
import { engineLogo } from "@/lib/databases/engine-logo";
import { shortVersion } from "@/lib/databases/short-version";

// Listed from `/databases/engines` instead, so filtered out of `/server/facts` runtimes.
const DATABASE_ENGINES = new Set(["mysql", "mariadb", "mongodb", "postgresql"]);

function Field({ icon: Icon, label, value, mono, copyLabel, className }) {
  return (
    // min-w-0: a grid item keeps min-width:auto, so without it the tile grows to
    // its widest word and `truncate` below never fires.
    <div
      className={cn(
        "flex min-w-0 items-center gap-2.5 rounded-lg border bg-muted/30 px-3.5 py-3",
        className,
      )}
    >
      <Icon className="size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1 space-y-0.5">
        <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
        {/* Mono at a scale size, with tighter tracking to fit long values. */}
        {value ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <p
                tabIndex={0}
                className={`truncate text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring ${mono ? "font-mono tracking-tight" : ""}`}
              >
                {value}
              </p>
            </TooltipTrigger>
            <TooltipContent className="max-w-sm break-all">{value}</TooltipContent>
          </Tooltip>
        ) : (
          <p className={`truncate text-sm font-medium ${mono ? "font-mono tracking-tight" : ""}`}>
            —
          </p>
        )}
      </div>
      {/* Always visible, not hover-revealed. */}
      {copyLabel ? <CopyButton value={value} label={copyLabel} /> : null}
    </div>
  );
}

// A band, not a grid card, so the facts fit one line and the charts below form a 2×2.
export async function ServerInfoCard({
  facts,
  health,
  siteAttention = [],
  engines = [],
  enginesFailed = false,
}) {
  const t = await getTranslations("serverDashboard");
  const tDatabases = await getTranslations("databases");
  // `mysql` is dropped: `/server/facts` builds it from `mysql --version`, which on
  // MariaDB reports the client under the wrong name.
  const runtimes = Object.entries(facts?.runtimes ?? {}).filter(
    ([name, version]) => version && !DATABASE_ENGINES.has(name),
  );
  // Installed, not running; whether services are up is the services badge's job.
  const installedEngines = engines.filter((engine) => engine.installed);
  const down = health?.down ?? [];

  if (!facts) {
    return (
      <Card className={PANEL_CARD}>
        <CardContent>
          <p className="flex items-center gap-2 rounded-lg border border-dashed bg-muted/30 px-4 py-6 text-sm text-muted-foreground">
            <CircleAlert className="size-4 shrink-0 text-destructive" />
            {t("loadFailed")}
          </p>
        </CardContent>
      </Card>
    );
  }

  return (
    // Same chrome as every other card on this page.
    <Card className={cn("[--card-spacing:--spacing(5)]", PANEL_CARD)}>
      <CardContent className="space-y-4">
        {/* Identity spans two of five columns; the hostname needs the width. */}
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
          {/* Emphasised over its neighbours: the only tile that answers "which machine is this". */}
          <div className="flex min-w-0 items-center gap-3 rounded-lg border border-primary/25 bg-primary/[0.07] px-3.5 py-3 shadow-e1 ring-1 ring-inset ring-background/60 sm:col-span-2">
            {/* The one filled brand-colour chip on the page. */}
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-e1">
              <Server className="size-5" />
            </span>
            <div className="min-w-0 flex-1 space-y-0.5">
              {facts.hostname ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <p
                      tabIndex={0}
                      className="truncate font-mono text-base font-semibold tracking-tight focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                      {facts.hostname}
                    </p>
                  </TooltipTrigger>
                  <TooltipContent className="max-w-sm break-all font-mono">
                    {facts.hostname}
                  </TooltipContent>
                </Tooltip>
              ) : (
                <p className="truncate font-mono text-base font-semibold tracking-tight">—</p>
              )}
              <p className="truncate text-xs text-muted-foreground">
                {[facts.os, facts.uptime?.human, facts.timezone].filter(Boolean).join(" · ") ||
                  "—"}
              </p>
            </div>
            {/* No reboot badge: the app shell already shows it on every page. */}
          </div>

          {/* public_ip first: `ip` is the interface address, often private on
              cloud hosts and useless for DNS. Falls back when unknown. */}
          <Field
            icon={Network}
            label={t("info.ip")}
            value={facts.public_ip ?? facts.ip}
            mono
            copyLabel={t("info.copyIp")}
          />
          <Field icon={Cpu} label={t("info.cpuModel")} value={facts.cpu?.model} />
          {/* Architecture rides along with the kernel. Full width at the
              two-column step so it is not alone beside a gap. */}
          <Field
            icon={Terminal}
            label={t("info.kernel")}
            value={[facts.kernel, facts.arch].filter(Boolean).join(" · ")}
            mono
            className="sm:col-span-2 xl:col-span-1"
          />
        </div>

        {down.length ? (
          <Link
            href="/services"
            className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive transition-colors hover:bg-destructive/10"
          >
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span>
              {t("info.servicesDown", {
                names: down.map((s) => s.label).join(", "),
                count: down.length,
              })}
            </span>
          </Link>
        ) : null}
      </CardContent>

      {/* Runtimes and service status share one footer, which keeps its shape when the
          services list is empty. */}
      <CardFooter className="flex flex-wrap items-center justify-between gap-x-6 gap-y-4">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          {/* mr-1 plus gap-2 so the label reads as a heading, not another chip. */}
          <span className="mr-1 text-xs uppercase tracking-wide text-muted-foreground">
            {t("info.runtimes")}
          </span>
          {runtimes.length || installedEngines.length || enginesFailed ? (
            <>
              {runtimes.map(([name, version]) => (
                <Badge key={name} variant="outline" className="gap-1.5 bg-card py-1 font-normal">
                  <span className="font-medium">{name}</span>
                  <span className="font-mono text-xs text-muted-foreground">{version}</span>
                </Badge>
              ))}

              {/* With logos, matching the databases page. */}
              {installedEngines.map((engine) => {
                const name = tDatabases(`engines.${engine.engine}`);
                return (
                  <Badge
                    key={`engine-${engine.engine}`}
                    variant="outline"
                    // The packaged string, for anyone who needs the build:
                    // "10.11.14-MariaDB-0ubuntu0.24.04.1".
                    title={engine.version ?? undefined}
                    className="gap-1.5 bg-card py-1 font-normal"
                  >
                    <EngineLogo engine={engine.engine} className="!h-4 w-auto max-w-16" />
                    {/* The logos are aria-hidden; a wordmark logo already shows
                        the name, so it is screen-reader only there. */}
                    {engineLogo(engine.engine)?.wordmark ? (
                      <span className="sr-only">{name}</span>
                    ) : (
                      <span className="font-medium">{name}</span>
                    )}
                    {shortVersion(engine.version) ? (
                      <span className="font-mono text-xs whitespace-nowrap text-muted-foreground">
                        {shortVersion(engine.version)}
                      </span>
                    ) : null}
                  </Badge>
                );
              })}

              {/* Stated, not omitted, so the row never contradicts the databases
                  page. Muted: nothing is broken on the server. */}
              {enginesFailed ? (
                <Badge
                  variant="outline"
                  className="gap-1.5 border-dashed bg-card py-1 font-normal text-muted-foreground"
                >
                  <CircleAlert className="size-3.5 shrink-0" aria-hidden />
                  {t("info.enginesUnknown")}
                </Badge>
              ) : null}
            </>
          ) : (
            <span className="text-sm text-muted-foreground">{t("info.noRuntimes")}</span>
          )}
        </div>

        {/* Site health and service health share one line. */}
        <div className="flex flex-wrap items-center gap-2">
          <SiteAttention findings={siteAttention} />
          <ServiceHealthLine health={health} down={down} t={t} />
        </div>
      </CardFooter>
    </Card>
  );
}

function ServiceHealthLine({ health, down, t }) {
  // No permission, or the request failed: there is no verdict to give.
  if (!health) return null;

  // A badge, matching the runtime chips beside it.
  if (down.length) {
    return (
      <Badge variant="destructive" className="gap-1.5 py-1 font-medium">
        <CircleAlert className="size-3.5 shrink-0" />
        {t("info.servicesDownCount", { count: down.length, total: health.total })}
      </Badge>
    );
  }

  // The server reported no services: say so, neutrally, not "All 0 running".
  if (!health.total) {
    return (
      <Badge variant="secondary" className="py-1 font-normal text-muted-foreground">
        {t("info.noServices")}
      </Badge>
    );
  }

  return (
    <Badge variant="success" className="gap-1.5 py-1 font-medium">
      <CircleCheck className="size-3.5 shrink-0" />
      {t("info.servicesOk", { count: health.total })}
    </Badge>
  );
}
