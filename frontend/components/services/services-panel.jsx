"use client";

import { useEffect, useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { CircleCheck, CircleStop, Clock, TriangleAlert } from "lucide-react";
import { listServices } from "@/lib/api/services";
import { servicesResponseSchema } from "@/lib/schemas/service";
import { ServicesTable } from "@/components/services/services-table";
import { ServiceAttentionList } from "@/components/services/service-attention-list";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { ServicesCards } from "@/components/services/services-cards";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { Tile } from "@/components/applications/app-status-tiles";

// Fast enough that CPU reads as live, light enough on the server.
const POLL_MS = 3000;

// Polls because `cpu_percent` is a delta between samples. "Checked at" updates only
// on success, so a stalled backend shows as a stopped clock, not stale numbers.
export function ServicesPanel({ initialServices, initialCheckedAt, phpVersions, canManage }) {
  const t = useTranslations("services");
  const format = useFormatter();
  const [services, setServices] = useState(initialServices);
  const [checkedAt, setCheckedAt] = useState(initialCheckedAt);
  // key → the action running on that service. Shared by both layouts.
  const [busy, setBusy] = useState({});

  const setRowBusy = (key, action) => setBusy((prev) => ({ ...prev, [key]: action }));

  // A fresh server render is newer than anything the poll holds.
  const [renderedWith, setRenderedWith] = useState(initialServices);
  if (renderedWith !== initialServices) {
    setRenderedWith(initialServices);
    setServices(initialServices);
    setCheckedAt(initialCheckedAt);
  }

  useEffect(() => {
    let active = true;

    async function tick() {
      // Skip while the tab is hidden, to avoid needless systemd calls.
      if (document.hidden) return;
      try {
        const { data } = await listServices();
        const parsed = servicesResponseSchema.safeParse(data);
        if (!active || !parsed.success) return;
        setServices(parsed.data.services);
        setCheckedAt(format.dateTime(new Date(), { timeStyle: "short" }));
      } catch {
        // Keep the last measured rows and timestamp; the stopped clock shows
        // the failure.
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

  // attention: never installed, or not running. running: the only rows where
  // Memory, CPU and boot mean anything. installing: in progress.
  const attention = services.filter(
    (s) => s.state === "install_failed" || (s.state !== "installing" && s.status === "failed"),
  );
  const installing = services.filter((s) => s.state === "installing");
  const running = services.filter(
    (s) => (s.state ?? "installed") === "installed" && s.status !== "failed",
  );
  // The table lists stopped units with running ones; the counts must not.
  const active = running.filter((s) => s.status !== "inactive");
  const stopped = running.length - active.length;
  const showRunning = running.length > 0 || services.length === 0;
  // Refresh sits in the Installed services header; when that card is hidden, in the
  // first card that is shown, so the page never loses it.
  const refreshIn = showRunning ? "running" : attention.length > 0 ? "attention" : "installing";

  return (
    <div className="space-y-6">
      {/* Colour only when something is wrong: amber for stopped, red for failed.
          1 / 2 / 4 across by the page's own width: auto-fit split them 3 + 1, and two
          to a phone row squeezed "Last checked" beside the refresh button mid-word. */}
      <div className="@container/tiles">
      <div className="grid gap-3 sm:gap-4 @min-[30rem]/tiles:grid-cols-2 @min-[52rem]/tiles:grid-cols-4">
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
        <Tile
          compact
          icon={CircleStop}
          label={t("tiles.stopped")}
          value={stopped > 0 ? t("tiles.count", { count: stopped }) : t("tiles.none")}
          tone={stopped > 0 ? "warning" : "neutral"}
        />
        <Tile
          compact
          icon={TriangleAlert}
          label={t("tiles.attention")}
          value={attention.length > 0 ? t("tiles.count", { count: attention.length }) : t("tiles.none")}
          tone={attention.length > 0 ? "destructive" : "neutral"}
        />
        {/* Moves only on a successful poll, so a stalled backend shows as a stopped clock. */}
        <Tile
          compact
          icon={Clock}
          label={t("tiles.checked")}
          value={<span className="tabular-nums">{checkedAt}</span>}
          tone="neutral"
        />
      </div>
      </div>

      {attention.length > 0 ? (
        <Section
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

      {/* Hidden when nothing is running (the summary already says so). Kept
          when there are no services at all, to explain an otherwise blank page. */}
      {showRunning ? (
      <Section
        title={t("sections.running.title")}
        hint={t("sections.running.hint")}
        action={<RefreshButton />}
      >
        {/* Container query, not lg: 900px is where the widest locale's headers fit. */}
        <div className="@container/svc">
        <div className="px-4 pb-4 @min-[900px]/svc:hidden">
          <ServicesCards
            data={running}
            phpVersions={phpVersions}
            canManage={canManage}
            busy={busy}
            setRowBusy={setRowBusy}
          />
        </div>
        <div className="hidden border-t @min-[900px]/svc:block">
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

// A card with the panel's card head; tables inside run edge to edge.
function Section({ title, hint, action, children }) {
  return (
    <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1">
      <div className="flex items-start gap-3 px-4 pt-4 pb-3">
        <div className="min-w-0 flex-1 space-y-1">
          <h2 className="text-[15px] font-semibold tracking-tight">{title}</h2>
          <p className="text-sm text-muted-foreground">{hint}</p>
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}
