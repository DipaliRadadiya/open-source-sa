import Link from "next/link";
import { getTranslations } from "next-intl/server";
import {
  TriangleAlert,
  Server,
  Network,
  Cpu,
  Terminal,
  Layers,
  CircleCheck,
  CircleAlert,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { CopyButton } from "@/components/ui/copy-button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";

function Field({ icon: Icon, label, value, mono, copyLabel }) {
  return (
    <div className="flex items-center gap-2.5 rounded-lg border bg-muted/30 px-3 py-2.5">
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
          the card to take away, and a control you have to discover isn't one.
          CopyButton renders nothing when there's no value to copy. */}
      {copyLabel ? <CopyButton value={value} label={copyLabel} /> : null}
    </div>
  );
}

export async function ServerInfoCard({ facts, health }) {
  const t = await getTranslations("serverDashboard");
  const runtimes = Object.entries(facts?.runtimes ?? {}).filter(([, v]) => v);

  return (
    <Card className="h-full">
      <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
        <div className="space-y-1">
          <CardTitle className="flex items-center gap-2 text-lg font-semibold">
            <Server className="size-4 text-primary" />
            {t("info.title")}
          </CardTitle>
          <CardDescription>{t("info.description")}</CardDescription>
        </div>
        {!facts ? null : facts.reboot_required ? (
          <Badge variant="warning" className="shrink-0 gap-1">
            <TriangleAlert className="size-3" />
            {t("info.rebootRequired")}
          </Badge>
        ) : (
          <Badge variant="success" className="shrink-0 gap-1">
            <CircleCheck className="size-3" />
            {t("info.healthy")}
          </Badge>
        )}
      </CardHeader>

      <CardContent className="space-y-4">
        {!facts ? (
          <p className="flex items-center gap-2 rounded-lg border border-dashed bg-muted/30 px-4 py-6 text-sm text-muted-foreground">
            <CircleAlert className="size-4 shrink-0 text-destructive" />
            {t("loadFailed")}
          </p>
        ) : (
        <>
        {/* Identity banner — the two facts you look for first. */}
        <div className="flex items-center gap-3 rounded-lg border bg-primary/5 px-3.5 py-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
            <Server className="size-5" />
          </span>
          <div className="min-w-0">
            {facts?.hostname ? (
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
              {[facts?.os, facts?.uptime?.human, facts?.timezone]
                .filter(Boolean)
                .join(" · ") || "—"}
            </p>
          </div>
        </div>

        <div className="grid gap-2.5 sm:grid-cols-2">
          <Field
            icon={Network}
            label={t("info.ip")}
            value={facts?.ip}
            mono
            copyLabel={t("info.copyIp")}
          />
          <Field icon={Cpu} label={t("info.cpuModel")} value={facts?.cpu?.model} />
          <Field icon={Terminal} label={t("info.kernel")} value={facts?.kernel} mono />
          <Field icon={Layers} label={t("info.arch")} value={facts?.arch} mono />
        </div>

        <div className="space-y-2 border-t pt-3.5">
          <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t("info.runtimes")}
          </p>
          <div className="flex flex-wrap gap-1.5">
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
              <span className="text-sm text-muted-foreground">
                {t("info.noRuntimes")}
              </span>
            )}
          </div>
        </div>

        {/* One line, not a second services list. The runtimes above say which
            versions are installed; this says whether they are actually up —
            which is the question a dashboard exists to answer. Silent grey
            when all is well, loud only when something is genuinely down. */}
        {health ? (
          health.down.length ? (
            <Link
              href="/services"
              className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive transition-colors hover:bg-destructive/10"
            >
              <CircleAlert className="mt-0.5 size-4 shrink-0" />
              <span>
                {t("info.servicesDown", {
                  names: health.down.map((s) => s.label).join(", "),
                  count: health.down.length,
                })}
              </span>
            </Link>
          ) : (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <CircleCheck className="size-4 shrink-0 text-success" />
              {t("info.servicesOk", { count: health.total })}
            </p>
          )
        ) : null}
        </>
        )}
      </CardContent>
    </Card>
  );
}
