"use client";

import { useEffect, useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { listServices } from "@/lib/api/services";
import { servicesResponseSchema } from "@/lib/schemas/service";
import { ServicesTable } from "@/components/services/services-table";
import { ServicesCards } from "@/components/services/services-cards";
import { RefreshButton } from "@/components/data-table/refresh-button";

// Fast enough that CPU reads as live, slow enough to stay off the box's back.
const POLL_MS = 3000;

/**
 * Owns the services list on the client so CPU can exist at all: `cpu_percent`
 * is a delta between two samples, so the value only appears once we've polled
 * twice. The poll therefore isn't a convenience — without it that column is
 * permanently empty.
 *
 * The "checked at" stamp is updated by the same poll, and ONLY on success. If
 * the backend stops answering the time visibly stops advancing, so the page
 * goes stale in the open rather than quietly serving old numbers as current —
 * the failure mode we hit with the log activity dots.
 */
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
      // Nothing to show while hidden, and a background tab shouldn't keep
      // shelling out to systemd on the user's server.
      if (document.hidden) return;
      try {
        const { data } = await listServices();
        const parsed = servicesResponseSchema.safeParse(data);
        if (!active || !parsed.success) return;
        setServices(parsed.data.services);
        setCheckedAt(format.dateTime(new Date(), { timeStyle: "short" }));
      } catch {
        // Leave both the rows and the timestamp alone: the numbers on screen
        // are the last ones we actually measured, and the clock not moving is
        // how the reader finds out.
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

  const failedCount = services.filter((s) => s.status === "failed").length;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-end gap-2">
        <span className="text-xs tabular-nums text-muted-foreground">
          {t("checkedAt", { time: checkedAt })}
        </span>
        <RefreshButton />
      </div>

      {/* The reason most people open this page, answered before they scan the
          table. Only shown when true — a permanent "all healthy" strip would be
          noise, and a stale one would be a lie. */}
      {failedCount > 0 ? (
        <p
          role="alert"
          className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
          {t("failedSummary", { count: failedCount })}
        </p>
      ) : null}

      {/* Cards on narrow screens, the table from lg up. The table scrolls
          sideways on a phone, but its action buttons land off-screen with
          nothing hinting at a swipe — the one thing you opened the page to do
          is the one thing you can't see. */}
      <div className="lg:hidden">
        <ServicesCards
          data={services}
          phpVersions={phpVersions}
          canManage={canManage}
          busy={busy}
          setRowBusy={setRowBusy}
        />
      </div>
      <div className="hidden lg:block">
        <ServicesTable
          data={services}
          phpVersions={phpVersions}
          canManage={canManage}
          busy={busy}
          setRowBusy={setRowBusy}
        />
      </div>
    </div>
  );
}
