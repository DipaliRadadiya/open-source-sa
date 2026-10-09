import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import {
  Server,
  Cpu,
  Terminal,
  CircleCheck,
  CircleAlert,
  Monitor,
  MemoryStick,
  HardDrive,
  Globe,
  Hexagon,
  Binary,
  Database,
  ChevronRight,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import { Badge } from "@/components/ui/badge";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { shortVersion } from "@/lib/databases/short-version";

// Listed from `/databases/engines` instead, so filtered out of `/server/facts` runtimes.
const DATABASE_ENGINES = new Set(["mysql", "mariadb", "mongodb", "postgresql"]);

// Product names, shown as-is in every language.
const RUNTIMES = {
  nginx: { name: "Nginx", logo: "/runtimes/nginx.svg", web: true },
  apache: { name: "Apache", logo: "/runtimes/apache.svg", web: true },
  openlitespeed: { name: "OpenLiteSpeed", logo: null, web: true },
  php: { name: "PHP", logo: "/site-types/php.svg" },
  node: { name: "Node.js", logo: "/runtimes/node.svg" },
  redis: { name: "Redis", logo: "/runtimes/redis.svg" },
};

// What a site depends on: the web server, PHP, Node.js and one database. The
// rest (Redis, a second engine) sits behind "+N more" on the Services page.
const MAIN_RUNTIMES = new Set(["nginx", "apache", "openlitespeed", "php", "node"]);
const MAIN_ENGINE_ORDER = ["mariadb", "mysql", "postgresql", "mongodb"];
const ENGINE_MARKS = new Set(MAIN_ENGINE_ORDER);

function Fact({ icon: Icon, label, value, mono }) {
  return (
    <div className="flex min-w-0 items-center gap-3 rounded-lg px-2 py-2">
      <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted/70 text-muted-foreground ring-1 ring-border/70">
        <Icon className="size-4" />
      </span>
      <div className="min-w-0 flex-1">
        <dt className="text-xs text-muted-foreground">{label}</dt>
        <dd className="flex min-w-0 items-center gap-1">
          {value ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <span
                  tabIndex={0}
                  className={cn(
                    "truncate text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
                    mono && "font-mono tracking-tight",
                  )}
                >
                  {value}
                </span>
              </TooltipTrigger>
              <TooltipContent className="max-w-sm break-all">{value}</TooltipContent>
            </Tooltip>
          ) : (
            <span className="text-sm font-medium">—</span>
          )}
        </dd>
      </div>
    </div>
  );
}

function SoftwareChip({ logo, icon: Icon = Hexagon, name, version, title }) {
  return (
    <li title={title} className="flex min-w-0 items-center gap-3 rounded-xl bg-muted/40 p-3 ring-1 ring-border/70">
      <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-card shadow-e1 ring-1 ring-border/70 dark:bg-white">
        {logo ?? <Icon className="size-4 text-muted-foreground" aria-hidden />}
      </span>
      <span className="min-w-0">
        <span className="block truncate text-sm font-medium">{name}</span>
        {version ? (
          <span className="block truncate font-mono text-xs text-muted-foreground">{version}</span>
        ) : null}
      </span>
    </li>
  );
}

// One full-width card: the machine's facts, then the software its sites run on.
// No IP or uptime here: the banner above shows both (Krishna, 6 Oct).
export async function ServerInfoCard({ facts, health, engines = [], enginesFailed = false, canViewServices = false }) {
  const t = await getTranslations("serverDashboard");

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

  const cores = Number(facts.cpu?.cores) || 0;
  const processor = facts.cpu?.model
    ? cores
      ? t("info.processor", { model: facts.cpu.model, count: cores })
      : facts.cpu.model
    : null;

  return (
    <Card className={cn("[--card-spacing:--spacing(5)]", PANEL_CARD)}>
      <CardHeader>
        <CardTitle as="h2">{t("info.title")}</CardTitle>
        <CardDescription>{t("info.description")}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        {/* Eight facts so the four-across rows are always full. */}
        <dl className="grid gap-x-4 gap-y-1 sm:grid-cols-2 xl:grid-cols-4">
          <Fact icon={Server} label={t("info.hostname")} value={facts.hostname} mono />
          <Fact icon={Monitor} label={t("info.os")} value={facts.os} />
          <Fact icon={Cpu} label={t("info.cpuModel")} value={processor} />
          <Fact icon={MemoryStick} label={t("info.memory")} value={facts.memory_total_human} />
          <Fact icon={HardDrive} label={t("info.disk")} value={facts.disk_total_human} />
          <Fact icon={Terminal} label={t("info.kernel")} value={facts.kernel} mono />
          <Fact icon={Binary} label={t("info.arch")} value={facts.arch} mono />
          <Fact icon={Globe} label={t("info.timezone")} value={facts.timezone} mono />
        </dl>
        <MainSoftware
          facts={facts}
          health={health}
          engines={engines}
          enginesFailed={enginesFailed}
          canViewServices={canViewServices}
        />
      </CardContent>
    </Card>
  );
}

// The software sites depend on, as equal tiles across the card's full width.
async function MainSoftware({ facts, health, engines, enginesFailed, canViewServices }) {
  const t = await getTranslations("serverDashboard");
  const tDatabases = await getTranslations("databases");
  // `mysql` is dropped: `/server/facts` builds it from `mysql --version`, which on
  // MariaDB reports the client under the wrong name.
  const runtimes = Object.entries(facts.runtimes ?? {}).filter(
    ([name, version]) => version && !DATABASE_ENGINES.has(name),
  );
  // Installed, not running; whether services are up is the services badge's job.
  const installedEngines = engines.filter((engine) => engine.installed);
  const down = health?.down ?? [];
  const mainRuntimes = runtimes
    .filter(([name]) => MAIN_RUNTIMES.has(name))
    // Web server first, then the languages.
    .sort(([a], [b]) => Number(Boolean(RUNTIMES[b]?.web)) - Number(Boolean(RUNTIMES[a]?.web)));
  const engineRank = (engine) => {
    const rank = MAIN_ENGINE_ORDER.indexOf(engine.engine);
    return rank === -1 ? MAIN_ENGINE_ORDER.length : rank;
  };
  const [mainEngine, ...otherEngines] = [...installedEngines].sort((a, b) => engineRank(a) - engineRank(b));
  const more = runtimes.length - mainRuntimes.length + otherEngines.length;


  return (
    <section className="border-t pt-5">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold">{t("info.mainSoftware")}</h3>
        <ServiceHealthLine health={health} down={down} t={t} />
      </div>
      <div>
        {runtimes.length || installedEngines.length || enginesFailed ? (
          <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            {mainRuntimes.map(([name, version]) => {
              const known = RUNTIMES[name];
              return (
                <SoftwareChip
                  key={name}
                  name={known?.name ?? name}
                  version={version}
                  logo={
                    known?.logo ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={known.logo} alt="" className="size-5 object-contain" />
                    ) : null
                  }
                />
              );
            })}

            {mainEngine ? (
              <SoftwareChip
                name={tDatabases(`engines.${mainEngine.engine}`)}
                // The packaged string, for anyone who needs the build:
                // "10.11.14-MariaDB-0ubuntu0.24.04.1".
                title={mainEngine.version ?? undefined}
                version={shortVersion(mainEngine.version)}
                // The mark only: a wordmark is unreadable at tile size, so the name is printed.
                logo={
                  ENGINE_MARKS.has(mainEngine.engine) ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={`/runtimes/${mainEngine.engine}.svg`} alt="" className="size-5 object-contain" />
                  ) : null
                }
                icon={Database}
              />
            ) : null}

            {/* Stated, not omitted, so the row never contradicts the databases
                page. Muted: nothing is broken on the server. */}
            {enginesFailed ? (
              <li className="flex items-center">
                <Badge
                  variant="outline"
                  className="gap-1.5 border-dashed bg-card py-1 font-normal text-muted-foreground"
                >
                  <CircleAlert className="size-3.5 shrink-0" aria-hidden />
                  {t("info.enginesUnknown")}
                </Badge>
              </li>
            ) : null}

            {more > 0 ? (
              <li>
                {canViewServices ? (
                  <Link
                    href="/services"
                    prefetch={false}
                    className="flex h-full items-center justify-center gap-0.5 rounded-xl border border-dashed px-3 py-3 text-sm font-medium text-primary transition-colors hover:border-primary/40 hover:bg-primary/5"
                  >
                    {t("info.moreSoftware", { count: more })}
                    <ChevronRight className="size-3.5" aria-hidden />
                  </Link>
                ) : (
                  <span className="flex h-full items-center justify-center rounded-xl border border-dashed px-3 py-3 text-sm text-muted-foreground">
                    {t("info.moreSoftware", { count: more })}
                  </span>
                )}
              </li>
            ) : null}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">{t("info.noRuntimes")}</p>
        )}
      </div>
    </section>
  );
}

function ServiceHealthLine({ health, down, t }) {
  // No permission, or the request failed: there is no verdict to give.
  if (!health) return null;

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
