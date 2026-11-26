import { Skeleton } from "@/components/ui/skeleton";
import { ListCard } from "@/components/data-table/list-card";
import { COVERAGE_WIDTHS } from "@/components/backups/coverage-widths";
import { cn } from "@/lib/utils";

const CONFIGURED = 2;
const NOT_SET_UP = 4;

function Lines({ first = "w-24", second = "w-16" }) {
  return (
    <div className="space-y-1.5">
      <Skeleton className={cn("h-4", first)} />
      <Skeleton className={cn("h-3", second)} />
    </div>
  );
}

const TOOLBAR = (
  <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
    <Skeleton className="h-9 w-full sm:w-64" />
    <Skeleton className="h-9 w-full sm:w-36" />
    <div className="flex items-center gap-3 sm:contents">
      <Skeleton className="h-9 min-w-0 flex-1 sm:w-36 sm:flex-none" />
      <Skeleton className="size-9 shrink-0 sm:ml-auto" />
    </div>
  </div>
);

const PAGER = (
  <div className="flex flex-col-reverse items-center gap-3 sm:flex-row sm:justify-between">
    <Skeleton className="h-9 w-32" />
    <Skeleton className="h-9 w-48" />
  </div>
);

function TableRows() {
  const cell = (key, extra) => cn("shrink-0 px-4", COVERAGE_WIDTHS[key], extra);
  const actions = cell("actions", "flex items-center justify-end gap-1");
  return (
    <div>
      <div className="flex h-11 items-center border-b">
        <div className="min-w-0 flex-1 px-4"><Skeleton className="h-3.5 w-24" /></div>
        <div className={cell("kind")}><Skeleton className="h-3.5 w-12" /></div>
        <div className={cell("schedule")}><Skeleton className="h-3.5 w-24" /></div>
        <div className={cell("storage")}><Skeleton className="h-3.5 w-16" /></div>
        <div className={cell("runs")}><Skeleton className="h-3.5 w-16" /></div>
        <div className={cell("actions")} />
      </div>
      {Array.from({ length: CONFIGURED }).map((_, i) => (
        <div key={`c${i}`} className="flex items-center border-b py-2.5">
          <div className="min-w-0 flex-1 space-y-1.5 px-4">
            <div className="flex items-center gap-2">
              <Skeleton className="h-4 w-16" />
              <Skeleton className="h-5 w-20 rounded-md" />
            </div>
            <Skeleton className="h-3 w-36" />
          </div>
          <div className={cell("kind")}><Skeleton className="h-4 w-20 max-w-full" /></div>
          <div className={cell("schedule", "space-y-1.5")}>
            <Skeleton className="h-4 w-20" />
            <Skeleton className="h-3 w-24 max-w-full" />
            <Skeleton className="h-3 w-12" />
          </div>
          <div className={cell("storage")}><Skeleton className="h-4 w-16" /></div>
          <div className={cell("runs")}><Lines first="w-28" second="w-32 max-w-full" /></div>
          <div className={actions}>
            <Skeleton className="h-8 w-28 rounded-lg" />
            <Skeleton className="size-8 rounded-lg" />
          </div>
        </div>
      ))}
      {Array.from({ length: NOT_SET_UP }).map((_, i) => (
        <div key={`n${i}`} className="flex items-center border-b py-2.5 last:border-b-0">
          <div className="min-w-0 flex-1 px-4"><Lines first="w-24" second="w-36" /></div>
          <div className="relative flex shrink-0 self-stretch">
            {["kind", "schedule", "storage", "runs"].map((key) => (
              <div key={key} className={cn("shrink-0", COVERAGE_WIDTHS[key])} />
            ))}
            <Skeleton className="absolute top-1/2 left-4 h-4 w-80 max-w-[calc(100%-2rem)] -translate-y-1/2" />
          </div>
          <div className={actions}>
            <Skeleton className="h-8 w-20 rounded-lg" />
            <span className="size-8 shrink-0" />
          </div>
        </div>
      ))}
    </div>
  );
}

function CardRows() {
  return (
    <div className="space-y-3">
      <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {Array.from({ length: CONFIGURED }).map((_, i) => (
          <li key={i} className="flex flex-col rounded-xl border bg-card shadow-sm">
            <div className="space-y-2 px-4 pt-4 pb-3">
              <Skeleton className="h-4 w-24" />
              <Skeleton className="h-3 w-40" />
              <Skeleton className="h-5 w-20 rounded-md" />
            </div>
            <div className="mx-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t pt-3 pb-4">
              {Array.from({ length: 4 }).map((_, f) => (
                <Lines key={f} first="w-12" second="w-20" />
              ))}
            </div>
            <div className="mt-auto flex items-center justify-end gap-1 border-t bg-muted/30 px-4 py-2.5">
              <Skeleton className="h-8 w-28 rounded-lg" />
              <Skeleton className="size-8 rounded-lg" />
            </div>
          </li>
        ))}
      </ul>
      <section className="overflow-hidden rounded-xl border bg-card shadow-sm">
        <div className="space-y-1.5 border-b bg-muted/30 px-4 py-3">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-3 w-56 max-w-full" />
        </div>
        <ul className="divide-y">
          {Array.from({ length: NOT_SET_UP }).map((_, i) => (
            <li key={i} className="flex items-center gap-3 px-4 py-2.5">
              <div className="min-w-0 flex-1"><Lines first="w-24" second="w-40 max-w-full" /></div>
              <Skeleton className="h-8 w-20 shrink-0 rounded-lg" />
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="flex flex-col items-start gap-3 rounded-xl border border-warning/30 bg-warning/5 p-4 sm:flex-row sm:items-center sm:gap-4">
        <Skeleton className="size-9 shrink-0 rounded-lg" />
        <div className="w-full min-w-0 flex-1 space-y-2">
          <Skeleton className="h-4 w-72 max-w-full" />
          <Skeleton className="h-3.5 w-96 max-w-full" />
        </div>
        <Skeleton className="h-9 w-full rounded-lg sm:w-36" />
      </div>
      <div className="@container/coverage">
      <ListCard from="c900" toolbar={TOOLBAR} footer={PAGER}>
        <div className="@min-[900px]/page:hidden">
          <CardRows />
        </div>
        <div className="hidden @min-[900px]/page:block">
          <TableRows />
        </div>
      </ListCard>
      </div>
    </div>
  );
}
