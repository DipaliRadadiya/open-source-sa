"use client";

import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ArchiveRestore, ArrowRight, ChevronRight, Cpu, FileCode, Lock, Power, Rocket, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { isRedeploying } from "@/lib/applications/settled";
import { isProcessDown } from "@/components/applications/application-status-badge";
import { SectionJumpLink } from "@/components/ui/section-jump-link";

const TONE = {
  success: { tile: "bg-success-soft text-success", value: "text-foreground" },
  warning: { tile: "bg-warning-soft text-warning", value: "text-[color-mix(in_oklch,var(--warning)_75%,var(--foreground))] dark:text-warning" },
  destructive: { tile: "bg-destructive-soft text-destructive", value: "text-destructive" },
  info: { tile: "bg-primary/10 text-primary", value: "text-foreground" },
  neutral: { tile: "bg-muted text-muted-foreground", value: "text-foreground" },
};

function Tile({ icon: Icon, label, value, detail, tone, href }) {
  const t = TONE[tone] ?? TONE.neutral;
  const body = (
    <>
      <div className="flex items-center gap-3">
        <span className={cn("flex size-9 shrink-0 items-center justify-center rounded-xl", t.tile)}>
          <Icon className="size-[18px]" aria-hidden />
        </span>
        <span className="min-w-0 flex-1 text-xs font-medium break-words text-muted-foreground">{label}</span>
        {href ? (
          <ChevronRight className="size-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" aria-hidden />
        ) : null}
      </div>
      <div className="min-w-0">
        {/* Wraps, never truncates: German "Deployment fehlgeschlagen" was cut mid-word. */}
        <p className={cn("text-[15px] font-semibold tracking-tight text-pretty break-words", t.value)}>{value}</p>
        {detail ? <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{detail}</p> : null}
      </div>
    </>
  );
  const className =
    "group flex min-w-0 flex-col gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-e1 transition-[border-color,box-shadow]";
  return href ? (
    <Link
      href={href}
      prefetch={false}
      className={cn(className, "hover:border-primary/30 hover:shadow-e2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring")}
    >
      {body}
    </Link>
  ) : (
    <div className={className}>{body}</div>
  );
}

// The four things people open an application for, each said once: is it up,
// is it secure, can it be restored, and what it runs (or how the last deploy
// went). Each tile links to the page that owns it. Problems nothing here
// covers (old PHP, DNS, disk…) follow as one line each.
export function AppStatusTiles({
  application,
  appId,
  https = null,
  backup = null,
  deploy = null,
  runtime = null,
  alerts = [],
}) {
  const t = useTranslations("applications");

  const tiles = [];

  if (application.is_disabled) {
    tiles.push({ key: "status", icon: Power, tone: "warning", value: t("paused"), detail: t("health.pausedDetail") });
  } else if (isRedeploying(application)) {
    tiles.push({ key: "status", icon: Power, tone: "info", value: t("deploying"), detail: t("tiles.deployingDetail") });
  } else if (isProcessDown(application)) {
    tiles.push({ key: "status", icon: Power, tone: "destructive", value: t("processStoppedBadge"), detail: t("tiles.stoppedDetail"), href: "#process" });
  } else {
    tiles.push({ key: "status", icon: Power, tone: "success", value: t("health.online"), detail: t("health.onlineDetail") });
  }
  tiles[0].label = t("tiles.status");

  // Each of these is only a tile when it was actually read: a failed read is not a fact.
  if (https) {
    const href = `/applications/${appId}/domains?tab=ssl`;
    const base = { key: "https", icon: Lock, label: t("domains.secured"), href };
    if (https.issuing) tiles.push({ ...base, tone: "info", value: t("domains.ssl.issuing") });
    else if (https.secured)
      tiles.push({
        ...base,
        tone: https.expiringSoon ? "warning" : "success",
        value: https.expiringSoon ? t("tiles.expiresSoon") : t("health.https"),
        detail: https.expiresHuman ? t("domains.expires", { when: https.expiresHuman }) : t("health.httpsDetail"),
      });
    else tiles.push({ ...base, tone: "destructive", value: t("attention.noCertificate"), detail: t("attention.issueCertificate") });
  }

  if (backup) {
    const state = !backup.target ? "unprotected" : !backup.target.enabled || backup.target.frequency === "manual" ? "paused" : "protected";
    const last = backup.target?.last_backup_at_human;
    tiles.push({
      key: "backups",
      icon: ArchiveRestore,
      label: t("backups.title"),
      href: `/applications/${appId}/backups`,
      tone: { protected: "success", paused: "warning", unprotected: "destructive" }[state],
      value: t(`backups.state.${state}`),
      detail:
        state === "unprotected"
          ? t("backups.setUp")
          : backup.noneKept || !last
            ? t("backups.noneKept")
            : t("tiles.lastBackup", { when: last }),
    });
  }

  if (deploy) {
    const href = `/applications/${appId}/deployment`;
    const base = { key: "deploy", icon: Rocket, label: t("tiles.deploy"), href };
    if (deploy.inFlight) tiles.push({ ...base, tone: "info", value: t("deploying"), detail: t("tiles.deployingDetail") });
    else if (application.failed_step)
      tiles.push({ ...base, tone: "destructive", value: t("tiles.deployFailed"), detail: t("tiles.deployFailedDetail") });
    else if (application.last_deployed_at_human)
      tiles.push({ ...base, tone: "success", value: t("tiles.deployed"), detail: application.last_deployed_at_human });
    else tiles.push({ ...base, tone: "neutral", value: t("tiles.notDeployed") });
  } else if (runtime) {
    tiles.push({
      key: "runtime",
      icon: runtime.kind === "static" ? FileCode : Cpu,
      label: t("tiles.runtime"),
      tone: "info",
      value: runtime.kind === "static" ? t("tiles.staticFiles") : runtime.label,
      detail: runtime.kind === "static" ? t("tiles.staticDetail") : runtime.href ? t("tiles.runtimeDetail") : null,
      href: runtime.href ?? null,
    });
  }

  return (
    <div className="space-y-3">
      {/* Two across on a phone (an odd last tile takes the row); from sm one row that fits
          three or four tiles, so no tile is left alone under the others. */}
      <div className="grid grid-cols-2 gap-3 max-sm:[&>*:last-child:nth-child(odd)]:col-span-2 sm:grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] sm:gap-4">
        {tiles.map(({ key, ...tile }) => (
          <Tile key={key} {...tile} />
        ))}
      </div>
      {alerts.length ? (
        <ul className="grid gap-2 lg:grid-cols-[repeat(auto-fit,minmax(26rem,1fr))]" aria-label={t("attention.title")}>
          {alerts.map((item) => (
            <li
              key={item.key}
              className="flex min-w-0 items-center gap-3 rounded-xl bg-card px-3 py-2.5 shadow-e1 ring-1 ring-border/70"
            >
              <span className="flex size-8 shrink-0 items-center justify-center rounded-xl bg-warning-soft text-warning">
                <TriangleAlert className="size-4" aria-hidden />
              </span>
              <p className="min-w-0 flex-1 text-sm font-medium wrap-anywhere">{item.label}</p>
              {item.action && item.href ? (
                item.href.startsWith("#") ? (
                  <SectionJumpLink href={item.href} className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline">
                    {item.action}
                    <ArrowRight className="size-3.5" aria-hidden />
                  </SectionJumpLink>
                ) : (
                  <Link
                    href={item.href}
                    prefetch={false}
                    className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline"
                  >
                    {item.action}
                    <ArrowRight className="size-3.5" aria-hidden />
                  </Link>
                )
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
