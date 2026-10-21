import { useCallback, useEffect, useRef, useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ArchiveRestore,
  ArrowUpCircle,
  CheckCircle2,
  Copy,
  Cpu,
  DatabaseBackup,
  Download,
  FlaskConical,
  ListChecks,
  Loader2,
  RefreshCw,
  Rocket,
  ShieldCheck,
  Upload,
  XCircle,
  Clock,
} from "lucide-react";
import { listOperations } from "@/lib/api/operations";
import { isActive, operationsResponseSchema } from "@/lib/schemas/operation";
import { operationHref } from "@/lib/operations/href";
import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { cn } from "@/lib/utils";

const POLL_MS = 5000;

const ICONS = {
  backup: DatabaseBackup,
  restore: ArchiveRestore,
  clone: Copy,
  staging: FlaskConical,
  deployment: Rocket,
  database_export: Download,
  database_import: Upload,
  runtime_install: Cpu,
  sync: RefreshCw,
  security_update: ShieldCheck,
  panel_update: ArrowUpCircle,
};

/**
 * Background jobs in the top bar (RC-20): a quiet icon, or "2 running" while work is
 * in flight, opening the running jobs and those finished in the last day. Each job
 * still shows its own progress on its own page; this is for when you have left it.
 */
export function OperationsIndicator({ canOpenApplications = false }) {
  const t = useTranslations("operations");
  const [active, setActive] = useState([]);
  const [all, setAll] = useState(null);
  const [open, setOpen] = useState(false);
  const known = useRef(null);

  const label = useCallback(
    (operation) => {
      const kind = t.has(`kinds.${operation.kind}`) ? t(`kinds.${operation.kind}`) : operation.kind;
      return operation.subject?.name ? `${kind} · ${operation.subject.name}` : kind;
    },
    [t],
  );

  const fetchList = useCallback(async (onlyActive) => {
    const { data } = await listOperations({ active: onlyActive });
    const parsed = operationsResponseSchema.safeParse(data);
    return parsed.success ? parsed.data.operations : null;
  }, []);

  // A job that left the running list finished while you were elsewhere: say how.
  const announce = useCallback(
    async (gone) => {
      const recent = await fetchList(false).catch(() => null);
      if (!recent) return;
      setAll(recent);
      for (const previous of gone) {
        const now = recent.find((row) => row.kind === previous.kind && String(row.id) === String(previous.id));
        if (!now || isActive(now)) continue;
        if (now.state === "failed") toast.error(t("toast.failed", { job: label(now) }));
        else toast.success(t("toast.done", { job: label(now) }));
      }
    },
    [fetchList, label, t],
  );

  useEffect(() => {
    let stopped = false;

    async function tick() {
      if (document.hidden) return;
      try {
        const rows = await fetchList(true);
        if (stopped || !rows) return;
        const running = rows.filter(isActive);
        const key = (row) => `${row.kind}:${row.id}`;
        // The first answer is the starting point, not news.
        if (known.current) {
          const ids = new Set(running.map(key));
          const gone = known.current.filter((row) => !ids.has(key(row)));
          if (gone.length) announce(gone);
        }
        known.current = running;
        setActive(running);
      } catch {
        // A failed poll keeps the last known state; the next tick tries again.
      }
    }

    tick();
    const id = setInterval(tick, POLL_MS);
    document.addEventListener("visibilitychange", tick);
    return () => {
      stopped = true;
      clearInterval(id);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [fetchList, announce]);

  // The full list only while the panel is open.
  useEffect(() => {
    if (!open) return undefined;
    let stopped = false;
    const load = () =>
      fetchList(false)
        .then((rows) => !stopped && rows && setAll(rows))
        .catch(() => !stopped && setAll((current) => current ?? []));
    load();
    const id = setInterval(load, POLL_MS);
    return () => {
      stopped = true;
      clearInterval(id);
    };
  }, [open, fetchList]);

  const running = active.length;
  const rows = all ?? active;
  const runningRows = rows.filter(isActive);
  const finishedRows = rows.filter((row) => !isActive(row));

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            {running > 0 ? (
              <Button variant="neutral" size="sm" className="gap-1.5" aria-label={t("trigger", { count: running })}>
                <Loader2 className="size-4 animate-spin text-primary" />
                <span className="tabular-nums">{running}</span>
                <span className="max-sm:sr-only">{t("running")}</span>
              </Button>
            ) : (
              <Button variant="neutral" size="icon" aria-label={t("title")}>
                <ListChecks className="size-4" />
              </Button>
            )}
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>{t("title")}</TooltipContent>
      </Tooltip>

      <PopoverContent align="end" className="w-[22rem] p-0 sm:w-96">
        <div className="border-b px-4 py-3">
          <p className="text-sm font-semibold">{t("title")}</p>
          <p className="text-xs text-muted-foreground">{t("description")}</p>
        </div>

        <div className="max-h-[60vh] overflow-y-auto">
          {all === null && running === 0 ? (
            <p className="flex items-center gap-2 px-4 py-6 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" />
              {t("loading")}
            </p>
          ) : rows.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">{t("empty")}</p>
          ) : (
            <>
              {runningRows.length > 0 ? (
                <Group title={t("groups.running")}>
                  {runningRows.map((row) => (
                    <Row key={`${row.kind}:${row.id}`} operation={row} label={label(row)} href={operationHref(row, { canOpenApplications })} onNavigate={() => setOpen(false)} />
                  ))}
                </Group>
              ) : null}
              {finishedRows.length > 0 ? (
                <Group title={t("groups.finished")}>
                  {finishedRows.map((row) => (
                    <Row key={`${row.kind}:${row.id}`} operation={row} label={label(row)} href={operationHref(row, { canOpenApplications })} onNavigate={() => setOpen(false)} />
                  ))}
                </Group>
              ) : null}
            </>
          )}
        </div>

        <p className="border-t px-4 py-2.5 text-xs text-muted-foreground">{t("footer")}</p>
      </PopoverContent>
    </Popover>
  );
}

function Group({ title, children }) {
  return (
    <div className="py-1">
      <p className="px-4 pt-2 pb-1 font-mono text-xs tracking-[0.08em] text-muted-foreground uppercase">{title}</p>
      <ul>{children}</ul>
    </div>
  );
}

function Row({ operation, label, href, onNavigate }) {
  const t = useTranslations("operations");
  const Icon = ICONS[operation.kind] ?? Clock;
  const failed = operation.state === "failed";
  const status =
    operation.state === "waiting"
      ? t("state.waiting")
      : operation.state === "running"
        ? t("state.running")
        : failed
          ? t("state.failed", { time: operation.finished_at_human ?? "" })
          : t("state.done", { time: operation.finished_at_human ?? "" });
  const StateIcon = isActive(operation) ? Loader2 : failed ? XCircle : CheckCircle2;

  const body = (
    <>
      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/40">
        <Icon className="size-4 text-muted-foreground" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium">{label}</span>
        <span
          className={cn(
            "mt-0.5 flex items-center gap-1 text-xs",
            failed ? "text-destructive" : isActive(operation) ? "text-primary" : "text-muted-foreground",
          )}
        >
          <StateIcon className={cn("size-3 shrink-0", isActive(operation) && "animate-spin")} />
          {status}
        </span>
      </span>
    </>
  );

  return (
    <li>
      {href ? (
        <Link href={href} prefetch={false} onClick={onNavigate} className="flex items-center gap-3 px-4 py-2 transition-colors hover:bg-muted/50">
          {body}
        </Link>
      ) : (
        <div className="flex items-center gap-3 px-4 py-2">{body}</div>
      )}
    </li>
  );
}
