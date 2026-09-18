import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { Server, Network, Cpu, Terminal, CircleCheck, CircleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import { Badge } from "@/components/ui/badge";
import { CopyButton } from "@/components/ui/copy-button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Card, CardContent, CardFooter } from "@/components/ui/card";
import { SiteAttention } from "@/components/dashboard/site-attention";

function Field({ icon: Icon, label, value, mono, copyLabel, className }) {
  return (
    // min-w-0: a grid item keeps min-width:auto, so without it this tile grows
    // to the widest word it contains and `truncate` below never fires — the
    // value just runs past the card edge, unclipped and with no ellipsis.
    <div
      className={cn(
        "flex min-w-0 items-center gap-2.5 rounded-lg border bg-muted/30 px-3.5 py-3",
        className,
      )}
    >
      <Icon className="size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1 space-y-0.5">
        <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
        {/* Mono stays on the type scale and buys its width back with tighter
            tracking. It was text-[13px] — an off-scale size invented to stop
            the kernel string truncating, which is a layout problem being paid
            for in typography. */}
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
      {/* Always visible, not hover-revealed: this is the value people came to
          the card to take away, and a control you have to discover isn't one. */}
      {copyLabel ? <CopyButton value={value} label={copyLabel} /> : null}
    </div>
  );
}

/**
 * A full-width band under the page title, not a card in the metrics grid.
 *
 * This is reference data — which machine am I on, what is its address, what is
 * installed — read once on arrival and then ignored. In a half-width column it
 * was as tall as a chart and forced the facts into a cramped 2×2; across the
 * page they fit on one line and the band costs a third of the height, which is
 * what lets the four charts below sit in an even 2×2 grid.
 */
export async function ServerInfoCard({ facts, health, siteAttention = [] }) {
  const t = await getTranslations("serverDashboard");
  const runtimes = Object.entries(facts?.runtimes ?? {}).filter(([, v]) => v);
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
        {/* Identity takes two columns of five — the hostname is the heading of
            this page, so it gets the width the other facts don't need. Three
            fields, not four: at a quarter of the row every value truncated. */}
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
          {/* One step above its neighbours in size, weight and tint — which is
              three levers where the research says pick one. It earns them: this
              is the only tile that answers "which machine is this", and at the
              same 14px as the IP beside it the row read as four equal facts. */}
          <div className="flex min-w-0 items-center gap-3 rounded-lg border border-primary/25 bg-primary/[0.07] px-3.5 py-3 shadow-e1 ring-1 ring-inset ring-background/60 sm:col-span-2">
            {/* Solid, not tinted. A 10%-alpha chip is the same treatment the
                three secondary tiles' icons get, so identity was being marked
                as important with the same ink as everything around it. This is
                the one place on the page that gets the filled brand colour. */}
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
            {/* No reboot badge here: the app shell already banners it across
                the top of every page, and a duplicate only cost the hostname
                the width it needs to be readable. */}
          </div>

          {/* public_ip first: `ip` is whatever the machine sees on its own
              interfaces, which on a cloud host is a private address nobody can
              point DNS at — and pointing DNS at it is what this field is
              copied for. Falls back when the server could not determine one. */}
          <Field
            icon={Network}
            label={t("info.ip")}
            value={facts.public_ip ?? facts.ip}
            mono
            copyLabel={t("info.copyIp")}
          />
          <Field icon={Cpu} label={t("info.cpuModel")} value={facts.cpu?.model} />
          {/* Architecture rides along with the kernel rather than taking a
              tile of its own — it is one short token nobody looks up alone. */}
          {/* Full width at the two-column step. Five tiles in a 2-col grid with
              identity spanning two leaves this one alone on the last row with a
              hole beside it — measured at 768px, where it was the only ragged
              edge on the page. */}
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

      {/*
       * "What is installed" and "is it running" are one question, so they share
       * one footer — and it is a real CardFooter now, not a bordered div at the
       * bottom of the content.
       *
       * The difference is not cosmetic. As a plain row the runtimes sat in the
       * card's own padding with nothing but a hairline above them, so on a
       * server whose services list comes back empty the right half vanished and
       * the badges read as a line someone forgot to finish. A footer is a
       * region: it keeps its shape whatever is in it.
       */}
      {/* gap-y-2, not 3: when the row wraps the services badge should land
          directly under the runtimes it belongs with, not float a line below. */}
      <CardFooter className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          {/* mr-1 and a full gap-2 between chips: at gap-1.5 the label sat as
              close to the first badge as the badges sat to each other, so
              "RUNTIMES" read as one more chip in the row rather than as its
              heading. */}
          <span className="mr-1 text-xs uppercase tracking-wide text-muted-foreground">
            {t("info.runtimes")}
          </span>
          {runtimes.length ? (
            runtimes.map(([name, version]) => (
              <Badge key={name} variant="outline" className="gap-1.5 bg-card py-1 font-normal">
                <span className="font-medium">{name}</span>
                <span className="font-mono text-[11px] text-muted-foreground">{version}</span>
              </Badge>
            ))
          ) : (
            <span className="text-sm text-muted-foreground">{t("info.noRuntimes")}</span>
          )}
        </div>

        {/* Two chips, one question. "Is the machine running" and "are the sites
            on it healthy" belong on the same line — and putting site health
            here rather than in a block of its own costs the dashboard no height
            at all, which is the whole reason it is here. */}
        <div className="flex flex-wrap items-center gap-2">
          <SiteAttention findings={siteAttention} />
          <ServiceHealthLine health={health} down={down} t={t} />
        </div>
      </CardFooter>
    </Card>
  );
}

/**
 * Whether the machine's services are running — in every case, including the
 * ones that used to render nothing.
 *
 * The old condition was `health?.total && !down.length`, which is silent on
 * three different situations that mean three different things: no permission to
 * read services, a server that reports none at all, and a server with something
 * down. Only the first of those deserves silence — we genuinely cannot say
 * anything. The other two were reported as the summary having disappeared.
 */
function ServiceHealthLine({ health, down, t }) {
  // No permission, or the request failed. Claiming anything here would be
  // inventing a verdict out of a missing answer.
  if (!health) return null;

  /*
   * A badge, not a sentence in the corner.
   *
   * As loose text on the far right it read as a caption that had drifted away
   * from the runtimes beside it — two different kinds of thing sharing a row.
   * Wearing the same pill shape as the runtime badges makes the footer one
   * group of status chips, which is what it always was.
   */
  if (down.length) {
    return (
      <Badge variant="destructive" className="gap-1.5 py-1 font-medium">
        <CircleAlert className="size-3.5 shrink-0" />
        {t("info.servicesDownCount", { count: down.length, total: health.total })}
      </Badge>
    );
  }

  // "All 0 services running" is not a reassurance — it is a sentence about
  // nothing. Say what is actually true: the server reported none. Neutral, not
  // red: nothing is broken, there is simply nothing to report.
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
