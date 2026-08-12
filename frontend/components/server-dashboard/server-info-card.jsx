import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { Server, Network, Cpu, Terminal, CircleCheck, CircleAlert } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { CopyButton } from "@/components/ui/copy-button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Card, CardContent } from "@/components/ui/card";

function Field({ icon: Icon, label, value, mono, copyLabel }) {
  return (
    // min-w-0: a grid item keeps min-width:auto, so without it this tile grows
    // to the widest word it contains and `truncate` below never fires — the
    // value just runs past the card edge, unclipped and with no ellipsis.
    <div className="flex min-w-0 items-center gap-2.5 rounded-lg border bg-muted/30 px-3 py-2.5">
      <Icon className="size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
          {label}
        </p>
        {value ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <p
                tabIndex={0}
                className={`truncate text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring ${mono ? "font-mono text-[13px]" : ""}`}
              >
                {value}
              </p>
            </TooltipTrigger>
            <TooltipContent className="max-w-sm break-all">{value}</TooltipContent>
          </Tooltip>
        ) : (
          <p className={`truncate text-sm font-medium ${mono ? "font-mono text-[13px]" : ""}`}>
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
export async function ServerInfoCard({ facts, health }) {
  const t = await getTranslations("serverDashboard");
  const runtimes = Object.entries(facts?.runtimes ?? {}).filter(([, v]) => v);
  const down = health?.down ?? [];

  if (!facts) {
    return (
      <Card>
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
    <Card>
      <CardContent className="space-y-3">
        {/* Identity takes two columns of five — the hostname is the heading of
            this page, so it gets the width the other facts don't need. Three
            fields, not four: at a quarter of the row every value truncated. */}
        <div className="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-5">
          <div className="flex min-w-0 items-center gap-3 rounded-lg border bg-primary/5 px-3.5 py-2.5 sm:col-span-2">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <Server className="size-4.5" />
            </span>
            <div className="min-w-0 flex-1">
              {facts.hostname ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <p
                      tabIndex={0}
                      className="truncate font-mono text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                      {facts.hostname}
                    </p>
                  </TooltipTrigger>
                  <TooltipContent className="max-w-sm break-all font-mono">
                    {facts.hostname}
                  </TooltipContent>
                </Tooltip>
              ) : (
                <p className="truncate font-mono text-sm font-semibold">—</p>
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

          <Field
            icon={Network}
            label={t("info.ip")}
            value={facts.ip}
            mono
            copyLabel={t("info.copyIp")}
          />
          <Field icon={Cpu} label={t("info.cpuModel")} value={facts.cpu?.model} />
          {/* Architecture rides along with the kernel rather than taking a
              tile of its own — it is one short token nobody looks up alone. */}
          <Field
            icon={Terminal}
            label={t("info.kernel")}
            value={[facts.kernel, facts.arch].filter(Boolean).join(" · ")}
            mono
          />
        </div>

        {/* "What is installed" and "is it running" are one question, so they
            share one quiet footer line — until something is down, when it
            becomes a red link across the full width of the band. */}
        <div className="space-y-2 border-t pt-3">
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

          <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="mr-0.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                {t("info.runtimes")}
              </span>
              {runtimes.length ? (
                runtimes.map(([name, version]) => (
                  <Badge key={name} variant="outline" className="gap-1.5 py-1 font-normal">
                    <span className="font-medium">{name}</span>
                    <span className="font-mono text-[11px] text-muted-foreground">
                      {version}
                    </span>
                  </Badge>
                ))
              ) : (
                <span className="text-sm text-muted-foreground">{t("info.noRuntimes")}</span>
              )}
            </div>

            {/* health.total is 0 when nothing monitorable is installed yet —
                "All 0 services running" is not a reassurance. */}
            {health?.total && !down.length ? (
              <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <CircleCheck className="size-4 shrink-0 text-success" />
                {t("info.servicesOk", { count: health.total })}
              </p>
            ) : null}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
