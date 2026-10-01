"use client";

import { useEffect, useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { listServices } from "@/lib/api/services";
import { servicesResponseSchema } from "@/lib/schemas/service";
import { ServicesTable } from "@/components/services/services-table";
import { ServiceAttentionList } from "@/components/services/service-attention-list";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { ServicesCards } from "@/components/services/services-cards";
import { RefreshButton } from "@/components/data-table/refresh-button";

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

  return (
    <div className="space-y-6">
      {/* The two key counts plus the sample time. Tinted only when something
          is wrong. */}
      <div
        className={`flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border px-4 py-3 text-sm ${
          attention.length > 0 ? "border-destructive/30 bg-destructive/5" : "bg-muted/30"
        }`}
      >
        {/* One phrase when all is well; the count only appears as the remainder
            when something is wrong. */}
        {attention.length > 0 ? (
          <>
            <span className="font-medium text-destructive">
              {t("summary.attention", { count: attention.length })}
            </span>
            <span className="text-muted-foreground">
              {t("summary.running", { count: active.length })}
            </span>
            {stopped > 0 ? (
              <span className="text-muted-foreground">{t("summary.stopped", { count: stopped })}</span>
            ) : null}
          </>
        ) : stopped > 0 ? (
          <>
            <span className="text-muted-foreground">{t("summary.running", { count: active.length })}</span>
            <span className="font-medium text-foreground">{t("summary.stopped", { count: stopped })}</span>
          </>
        ) : (
          <span className="text-muted-foreground">
            {t("summary.allRunning", { count: running.length })}
          </span>
        )}
        {installing.length > 0 ? (
          <span className="text-muted-foreground">
            {t("summary.installing", { count: installing.length })}
          </span>
        ) : null}
        {/* The stamp and its refresh button sit at page level, since they
            cover the whole page, not one section. */}
        <span className="ms-auto flex items-center gap-2 text-xs tabular-nums text-muted-foreground">
          {t("checkedAt", { time: checkedAt })}
          <RefreshButton />
        </span>
      </div>

      {attention.length > 0 ? (
        <Section title={t("sections.attention.title")} hint={t("sections.attention.hint")}>
          <ServiceAttentionList
            services={attention}
            phpVersions={phpVersions}
            canManage={canManage}
            busy={busy}
            setRowBusy={setRowBusy}
          />
        </Section>
      ) : null}

      {installing.length > 0 ? (
        <Section title={t("sections.installing.title")} hint={t("sections.installing.hint")}>
          <ul className="divide-y rounded-xl border">
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
      {running.length > 0 || services.length === 0 ? (
      <Section title={t("sections.running.title")} hint={t("sections.running.hint")}>
        {/* Container query, not lg: 900px is where the widest locale's headers fit. */}
        <div className="@container/svc">
        <div className="@min-[900px]/svc:hidden">
          <ServicesCards
            data={running}
            phpVersions={phpVersions}
            canManage={canManage}
            busy={busy}
            setRowBusy={setRowBusy}
          />
        </div>
        <div className="hidden @min-[900px]/svc:block">
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

function Section({ title, hint, children }) {
  return (
    <section className="space-y-3">
      <div className="space-y-0.5">
        <h2 className="font-semibold tracking-tight">{title}</h2>
        <p className="text-sm text-muted-foreground">{hint}</p>
      </div>
      {children}
    </section>
  );
}
