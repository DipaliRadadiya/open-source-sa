import { Skeleton } from "@/components/ui/skeleton";

const CARD = "rounded-2xl border border-border/70 bg-card shadow-e1";

// The setup page's own shape: header, overview, one card to install, the installed list.
export default function SetupLoading() {
  return (
    <div className="space-y-6" aria-hidden>
      <div className="flex items-start gap-4">
        <Skeleton className="size-12 shrink-0 rounded-2xl" />
        <div className="w-full space-y-2.5 pt-1">
          <Skeleton className="h-7 w-56" />
          <Skeleton className="h-4 w-96" />
        </div>
      </div>

      <div className="space-y-8">
        <div className={`${CARD} space-y-4 p-5`}>
          <Skeleton className="h-4 w-52" />
          <Skeleton className="h-2 w-full rounded-full" />
        </div>

        <div className="space-y-3">
          <SectionHead />
          <div className={`${CARD} flex items-center gap-4 p-4 sm:p-5`}>
            <Skeleton className="size-9 shrink-0 rounded-xl" />
            <div className="min-w-0 flex-1 space-y-2">
              <Skeleton className="h-4 w-28" />
              <Skeleton className="h-3 w-80" />
            </div>
            <Skeleton className="h-8 w-20 shrink-0 rounded-lg" />
          </div>
        </div>

        <div className="space-y-3">
          <SectionHead hint={false} />
          <div className={`${CARD} divide-y overflow-hidden`}>
            {["w-24", "w-16", "w-20", "w-28", "w-16"].map((width, i) => (
              <div key={i} className="flex items-center gap-3 px-4 py-3">
                <Skeleton className="size-9 shrink-0 rounded-xl" />
                <div className="min-w-0 flex-1 space-y-2">
                  <Skeleton className={`h-3.5 ${width}`} />
                  <Skeleton className="h-3 w-32" />
                </div>
                <Skeleton className="h-3.5 w-16 shrink-0" />
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

function SectionHead({ hint = true }) {
  return (
    <div className="space-y-2">
      <Skeleton className="h-4 w-36" />
      {hint ? <Skeleton className="h-3 w-72" /> : null}
    </div>
  );
}
