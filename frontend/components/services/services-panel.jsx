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

  // Three groups, because the reader is asking three different questions and a
  // single list answered none of them well. On a server whose engines all failed
  // to install, the table was every row empty except its name.
  //
  //   attention   needs a person: never installed, or installed and not running
  //   running     has a unit that is up — the only rows where Memory, CPU and
  //               Start on boot mean anything, so they keep the table
  //   installing  in progress; nothing to do but wait
  const attention = services.filter(
    (s) => s.state === "install_failed" || (s.state !== "installing" && s.status === "failed"),
  );
  const installing = services.filter((s) => s.state === "installing");
  const running = services.filter(
    (s) => (s.state ?? "installed") === "installed" && s.status !== "failed",
  );

  return (
    <div className="space-y-6">
      {/* The two counts people open this page for, plus when the numbers were
          taken. Tinted only when something is actually wrong — a permanently
          coloured strip is decoration, and a permanently calm one that goes red
          is a signal. */}
      <div
        className={`flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border px-4 py-3 text-sm ${
          attention.length > 0 ? "border-destructive/30 bg-destructive/5" : "bg-muted/30"
        }`}
      >
        {/* One phrase when all is well: "Everything is running · 5 running"
            said the same thing twice. The count only earns its own slot when it
            is the REMAINDER after something has gone wrong. */}
        {attention.length > 0 ? (
          <>
            <span className="font-medium text-destructive">
              {t("summary.attention", { count: attention.length })}
            </span>
            <span className="text-muted-foreground">
              {t("summary.running", { count: running.length })}
            </span>
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
        {/* The stamp and the button that renews it, together, and in the one
            place that speaks for the whole page. In a section header the same
            button read as "refresh these rows". */}
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

      {/* Hidden when nothing is running: on a server whose engines all failed
          to install, this drew a full six-column table with one "No services
          detected" cell in it — a large empty frame saying what the summary
          line already said in two words.

          Kept when there are NO services at all, because then it is the only
          thing that can explain an otherwise blank page. */}
      {running.length > 0 || services.length === 0 ? (
      <Section title={t("sections.running.title")} hint={t("sections.running.hint")}>
        {/* Cards on narrow screens, the table from lg up. The table scrolls
            sideways on a phone, but its action buttons land off-screen with
            nothing hinting at a swipe — the one thing you opened the page to do
            is the one thing you can't see. */}
        <div className="lg:hidden">
          <ServicesCards
            data={running}
            phpVersions={phpVersions}
            canManage={canManage}
            busy={busy}
            setRowBusy={setRowBusy}
          />
        </div>
        <div className="hidden lg:block">
          <ServicesTable
            data={running}
            phpVersions={phpVersions}
            canManage={canManage}
            busy={busy}
            setRowBusy={setRowBusy}
          />
        </div>
      </Section>
      ) : null}
    </div>
  );
}

/**
 * A titled group of rows. Same shape as the setup page's sections: a heading
 * that says what the group is and one line saying why it is separate.
 */
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
