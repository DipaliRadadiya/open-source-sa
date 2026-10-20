import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { CircleAlert, ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import { Badge } from "@/components/ui/badge";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { shortVersion } from "@/lib/databases/short-version";

// Listed from `/databases/engines` instead, so filtered out of `/server/facts` runtimes.
const DATABASE_ENGINES = new Set(["mysql", "mariadb", "mongodb", "postgresql"]);

// Product names, shown as-is in every language.
const RUNTIMES = {
  nginx: { name: "Nginx", mark: "NGX", web: true },
  apache: { name: "Apache", mark: "APA", web: true },
  openlitespeed: { name: "OpenLiteSpeed", mark: "OLS", web: true },
  php: { name: "PHP", mark: "PHP" },
  node: { name: "Node.js", mark: "NODE" },
  redis: { name: "Redis", mark: "RDS" },
};

// What a site depends on: the web server, PHP, Node.js and one database. The
// rest (Redis, a second engine) fills the row while there is room, then "+N more".
const MAIN_RUNTIMES = new Set(["nginx", "apache", "openlitespeed", "php", "node"]);
const MAIN_ENGINE_ORDER = ["mariadb", "mysql", "postgresql", "mongodb"];
// Tiles in one row of the strip at its widest (`lg:grid-cols-5` below).
const ROW_TILES = 5;
// Steel UI (Krishna's prototype, 14 Oct): a short mono code in a square instead of the
// logo on a white tile, which looked odd on the dark theme (and the MariaDB seal is
// dark blue, so a dark tile would hide it).
const ENGINE_MARKS = { mariadb: "MDB", mysql: "MYSQL", postgresql: "PG", mongodb: "MGO" };

// Steel UI (Krishna's prototype, 14 Oct): facts are cells in one hairline grid. Each
// cell draws its right and bottom edge inside itself; the grid's -1px margins push
// the outermost ones under the card's clipped edge or the next section's border.
const CELL = "min-w-0 bg-card shadow-[inset_-1px_0_0_var(--border),inset_0_-1px_0_var(--border)]";

function Fact({ label, value, mono }) {
  return (
    <div className={cn(CELL, "px-4 py-3")}>
      <dt className="font-mono text-xs font-medium tracking-[0.1em] text-muted-foreground uppercase">{label}</dt>
      <dd className="mt-1 flex min-w-0 items-center gap-1">
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
  );
}

function SoftwareChip({ mark, name, version, title }) {
  return (
    <li title={title} className={cn(CELL, "flex items-center gap-3 px-4 py-3")}>
      <span
        aria-hidden
        className="flex h-9 min-w-9 shrink-0 items-center justify-center rounded-[2px] border bg-muted/40 px-1 font-mono text-xs font-semibold tracking-[0.04em] text-[var(--primary-700,var(--primary))] dark:text-primary"
      >
        {mark ?? name.slice(0, 3).toUpperCase()}
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
    <Card className={cn("gap-0 pb-0 [--card-spacing:--spacing(4)]", PANEL_CARD)}>
      {/* The description sits on the right in mono caps, as in the prototype. */}
      <CardHeader className="border-b max-sm:grid-cols-1">
        <CardTitle as="h2">{t("info.title")}</CardTitle>
        <CardAction className="self-center max-sm:col-start-1 max-sm:row-span-1 max-sm:row-start-2 max-sm:justify-self-start">
          <CardDescription className="font-mono text-xs tracking-[0.1em] uppercase">{t("info.description")}</CardDescription>
        </CardAction>
      </CardHeader>
      <CardContent className="p-0">
        {/* Eight facts so the four-across rows are always full. */}
        <dl className="-mr-px -mb-px grid sm:grid-cols-2 xl:grid-cols-4">
          <Fact label={t("info.hostname")} value={facts.hostname} mono />
          <Fact label={t("info.os")} value={facts.os} />
          <Fact label={t("info.cpuModel")} value={processor} />
          <Fact label={t("info.memory")} value={facts.memory_total_human} />
          <Fact label={t("info.disk")} value={facts.disk_total_human} />
          <Fact label={t("info.kernel")} value={facts.kernel} mono />
          <Fact label={t("info.arch")} value={facts.arch} mono />
          <Fact label={t("info.timezone")} value={facts.timezone} mono />
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
  // The rest go straight into the row while it has room (five tiles on a wide
  // screen); "+N more" only when they do not fit, and it then takes the last tile.
  const extraRuntimes = runtimes.filter(([name]) => !MAIN_RUNTIMES.has(name));
  const extras = [
    ...extraRuntimes.map(([name, version]) => ({ kind: "runtime", name, version })),
    ...otherEngines.map((engine) => ({ kind: "engine", engine })),
  ];
  const room = ROW_TILES - mainRuntimes.length - (mainEngine ? 1 : 0) - (enginesFailed ? 1 : 0);
  const inline = extras.length <= room ? extras : extras.slice(0, Math.max(0, room - 1));
  const more = extras.length - inline.length;


  return (
    <section className="border-t">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-[var(--card-head)] px-4 py-3">
        <h3 className="text-sm font-semibold">{t("info.mainSoftware")}</h3>
        <ServiceHealthLine health={health} down={down} t={t} />
      </div>
      <div>
        {runtimes.length || installedEngines.length || enginesFailed ? (
          <ul className="-mr-px -mb-px grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
            {mainRuntimes.map(([name, version]) => {
              const known = RUNTIMES[name];
              return (
                <SoftwareChip
                  key={name}
                  name={known?.name ?? name}
                  version={version}
                  mark={known?.mark}
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
                mark={ENGINE_MARKS[mainEngine.engine]}
              />
            ) : null}

            {inline.map((item) =>
              item.kind === "runtime" ? (
                <SoftwareChip
                  key={item.name}
                  name={RUNTIMES[item.name]?.name ?? item.name}
                  version={item.version}
                  mark={RUNTIMES[item.name]?.mark}
                />
              ) : (
                <SoftwareChip
                  key={item.engine.engine}
                  name={tDatabases(`engines.${item.engine.engine}`)}
                  title={item.engine.version ?? undefined}
                  version={shortVersion(item.engine.version)}
                  mark={ENGINE_MARKS[item.engine.engine]}
                />
              ),
            )}

            {/* Stated, not omitted, so the row never contradicts the databases
                page. Muted: nothing is broken on the server. */}
            {enginesFailed ? (
              <li className={cn(CELL, "flex items-center px-4 py-3")}>
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
              <li className={CELL}>
                {canViewServices ? (
                  <Link
                    href="/services"
                    prefetch={false}
                    className="flex h-full min-h-15 items-center justify-center gap-0.5 px-3 py-3 text-sm font-medium text-primary transition-colors hover:bg-primary/5"
                  >
                    {t("info.moreSoftware", { count: more })}
                    <ChevronRight className="size-3.5" aria-hidden />
                  </Link>
                ) : (
                  <span className="flex h-full min-h-15 items-center justify-center px-3 py-3 text-sm text-muted-foreground">
                    {t("info.moreSoftware", { count: more })}
                  </span>
                )}
              </li>
            ) : null}
          </ul>
        ) : (
          <p className="px-4 py-3 text-sm text-muted-foreground">{t("info.noRuntimes")}</p>
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
      <span aria-hidden className="size-1.5 shrink-0 bg-current" />
      {t("info.servicesOk", { count: health.total })}
    </Badge>
  );
}
