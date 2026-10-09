import { SkCard, SkHeader } from "@/components/ui/skeleton-kit";


// Staging area: the staging copy, push to production, delete.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="space-y-4">
        <SkCard action />
        <SkCard action />
        <SkCard className="border-destructive/30" action />
      </div>
    </div>
  );
}
