import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils";

/**
 * KPI stat card for the dashboard. Server-rendered — `icon` is a Lucide
 * component (renders fine in RSC), `value` should already be formatted.
 */
export function StatCard({ icon: Icon, title, value, hint, className }) {
  return (
    <Card className={cn("gap-0", className)}>
      <CardHeader className="flex flex-row items-center justify-between gap-2 pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">
          {title}
        </CardTitle>
        {Icon ? (
          <span className="flex shrink-0 items-center justify-center text-muted-foreground">
            <Icon className="size-4" />
          </span>
        ) : null}
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-semibold tracking-tight">{value}</div>
        {hint ? (
          <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
        ) : null}
      </CardContent>
    </Card>
  );
}
