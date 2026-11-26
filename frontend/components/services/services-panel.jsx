"use client";

import { HeadIcon } from "@/components/ui/head-icon";
import { useEffect, useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { CircleCheck, CircleStop, Clock, TriangleAlert, Download, Blocks } from "lucide-react";
import { cn } from "@/lib/utils";
import { listServices } from "@/lib/api/services";
import { servicesResponseSchema } from "@/lib/schemas/service";
import { ServicesTable } from "@/components/services/services-table";
import { ServiceAttentionList } from "@/components/services/service-attention-list";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { ServicesCards } from "@/components/services/services-cards";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { Tile } from "@/components/applications/app-status-tiles";

const POLL_MS = 3000;

// Polls because cpu_percent is a delta between samples.
export function ServicesPanel({ initialServices, initialCheckedAt, phpVersions, canManage }) {
  const t = useTranslations("services");
  const format = useFormatter();
  const [services, setServices] = useState(initialServices);
  const [checkedAt, setCheckedAt] = useState(initialCheckedAt);
  const [busy, setBusy] = useState({});

  const setRowBusy = (key, action) => setBusy((prev) => ({ ...prev, [key]: action }));

  const [renderedWith, setRenderedWith] = useState(initialServices);
  if (renderedWith !== initialServices) {
    setRenderedWith(initialServices);
    setServices(initialServices);
    setCheckedAt(initialCheckedAt);
  }

  useEffect(() => {
    let active = true;

    async function tick() {
      if (document.hidden) return;
      try {
        const { data } = await listServices();
        const parsed = servicesResponseSchema.safeParse(data);
        if (!active || !parsed.success) return;
        setServices(parsed.data.services);
        setCheckedAt(format.dateTime(new Date(), { timeStyle: "short" }));
      } catch {
        // retry on the next poll
      }
    }

    const id = setInterval(tick, POLL_MS);
    document.addEventListener("visibilitychange", tick);
    return () => {
      active = false;
      clearInterval(id);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [format]);

  const attention = services.filter(
    (s) => s.state === "install_failed" || (s.state !== "installing" && s.status === "failed"),
  );
  const installing = services.filter((s) => s.state === "installing");
  const running = services.filter(
    (s) => (s.state ?? "installed") === "installed" && s.status !== "failed",
  );
  const active = running.filter((s) => s.status !== "inactive");
  const stopped = running.length - active.length;
  const showRunning = running.length > 0 || services.length === 0;
  const refreshIn = showRunning ? "running" : attention.length > 0 ? "attention" : "installing";
  const healthy = stopped === 0 && attention.length === 0 && showRunning;
  const healthyLine = (
    <span className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
      <CircleCheck className={cn("size-3.5 shrink-0", active.length > 0 ? "text-success" : "text-muted-foreground")} />
      <span>{t("summary.healthyLine", { active: active.length, total: services.length })}</span>
      <span aria-hidden>·</span>
      <span className="tabular-nums">{t("summary.checkedAt", { time: checkedAt })}</span>
      {installing.length > 0 ? <span>· {t("summary.installing", { count: installing.length })}</span> : null}
    </span>
  );

  return (
    <div className="space-y-6">
      {healthy ? null : (
      <div className="@container/tiles">
      <div className={cn("grid grid-cols-2 gap-3 sm:gap-4 [&>*:last-child:nth-child(odd)]:col-span-2 @min-[52rem]/tiles:[&>*:last-child:nth-child(odd)]:col-span-1", TILE_COLUMNS[2 + (stopped > 0) + (attention.length > 0)])}>
        <Tile
          compact
          icon={CircleCheck}
          label={t("tiles.running")}
          value={t("tiles.runningValue", { active: active.length, total: services.length })}
          detail={
            installing.length > 0
              ? t("summary.installing", { count: installing.length })
              : null
          }
          tone={active.length > 0 ? "success" : "neutral"}
        />
        {stopped > 0 ? (
          <Tile
            compact
            icon={CircleStop}
            label={t("tiles.stopped")}
            value={t("tiles.count", { count: stopped })}
            tone="warning"
          />
        ) : null}
        {attention.length > 0 ? (
          <Tile
            compact
            icon={TriangleAlert}
            label={t("tiles.attention")}
            value={t("tiles.count", { count: attention.length })}
            tone="destructive"
          />
        ) : null}
        <Tile
          compact
          icon={Clock}
          label={t("tiles.checked")}
          value={<span className="tabular-nums">{checkedAt}</span>}
          tone="neutral"
        />
      </div>
      </div>
      )}

      {attention.length > 0 ? (
        <Section
          icon={TriangleAlert}
          iconTone="destructive"
          title={t("sections.attention.title")}
          hint={t("sections.attention.hint")}
          action={refreshIn === "attention" ? <RefreshButton /> : null}
        >
          <div className="px-4 pb-4">
            <ServiceAttentionList
              services={attention}
              phpVersions={phpVersions}
              canManage={canManage}
              busy={busy}
              setRowBusy={setRowBusy}
            />
          </div>
        </Section>
      ) : null}

      {installing.length > 0 ? (
        <Section
          icon={Download}
          title={t("sections.installing.title")}
          hint={t("sections.installing.hint")}
          action={refreshIn === "installing" ? <RefreshButton /> : null}
        >
          <ul className="divide-y border-t">
            {installing.map((service) => (
              <li key={service.key} className="flex items-center justify-between gap-3 p-4">
                <p className="min-w-0 truncate text-sm font-medium">{service.label}</p>
                <ServiceStatusBadge status={service.status} state={service.state} />
              </li>
            ))}
          </ul>
        </Section>
      ) : null}

      {showRunning ? (
      <Section
        icon={Blocks}
        title={t("sections.running.title")}
        hint={healthy ? healthyLine : null}
        action={<RefreshButton />}
      >
        <div className="@container/svc">
        <div className="px-4 pb-4 @min-[900px]/page:hidden">
          <ServicesCards
            data={running}
            phpVersions={phpVersions}
            canManage={canManage}
            busy={busy}
            setRowBusy={setRowBusy}
          />
        </div>
        <div className="hidden border-t @min-[900px]/page:block">
          <ServicesTable
            data={running}
            phpVersions={phpVersions}
            canManage={canManage}
            busy={busy}
            setRowBusy={setRowBusy}
          />
        </div>
        </div>
      </Section>
      ) : null}
    </div>
  );
}

const TILE_COLUMNS = {
  2: "@min-[52rem]/tiles:grid-cols-2",
  3: "@min-[52rem]/tiles:grid-cols-3",
  4: "@min-[52rem]/tiles:grid-cols-4",
};

function Section({ title, hint, action, icon = null, iconTone = "default", children }) {
  return (
    <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1">
      <div className={cn("flex gap-3 px-4 pt-4 pb-3", hint ? "items-start" : "items-center")}>
        <HeadIcon icon={icon} tone={iconTone} />
        <div className="min-w-0 flex-1 space-y-1">
          <h2 className="text-[15px] font-semibold tracking-tight">{title}</h2>
          {hint ? <p className="text-sm text-muted-foreground">{hint}</p> : null}
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}
