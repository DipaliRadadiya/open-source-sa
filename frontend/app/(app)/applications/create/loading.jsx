import { Skeleton } from "@/components/ui/skeleton";
import { SK_CARD, SkHeader, SkInput, SkOptions } from "@/components/ui/skeleton-kit";
import { cn } from "@/lib/utils";

// Create application (7 Oct): numbered step cards, each a title over a rule, then its body.
function StepCard({ children }) {
  return (
    <div className={cn(SK_CARD, "overflow-hidden")}>
      <div className="flex items-start gap-3 border-b px-5 py-3.5">
        <Skeleton className="size-7 shrink-0 rounded-full" />
        <div className="space-y-2">
          <Skeleton className="h-4 w-40" />
          <Skeleton className="h-3 w-72 max-w-full" />
        </div>
      </div>
      <div className="p-5">{children}</div>
    </div>
  );
}

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="mx-auto max-w-6xl space-y-6">
        <StepCard>
          <SkInput />
          <div className="mt-3 flex gap-2">
            {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-6 w-20 rounded-full" />)}
          </div>
          <div className="mt-4">
            <SkOptions count={6} columns={3} />
          </div>
        </StepCard>
        <StepCard>
          <div className="grid gap-5 sm:grid-cols-2">
            {[0, 1].map((i) => (
              <div key={i} className="space-y-2">
                <Skeleton className="h-3.5 w-24" />
                <SkInput />
              </div>
            ))}
          </div>
          <div className="mt-5">
            <SkOptions count={2} columns={2} />
          </div>
        </StepCard>
        <StepCard>
          <Skeleton className="h-10 w-full rounded-lg" />
        </StepCard>
      </div>
    </div>
  );
}
