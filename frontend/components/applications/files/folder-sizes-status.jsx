import { useFormatter, useNow, useTranslations } from "next-intl";
import { Loader2, RefreshCw, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";
import { parseApiWallClock } from "@/lib/format/api-date";

// `measured_at` is the API's clock, which runs in UTC; read as wall clock it is that instant.
export function FolderSizesStatus({ sizes }) {
  const t = useTranslations("applications.files.sizes");
  const format = useFormatter();
  const now = useNow({ updateInterval: 30000 });
  const { data, error, loading, measureAgain } = sizes;

  if (loading && !data) {
    return (
      <span className="inline-flex items-center gap-1.5" role="status">
        <Loader2 className="size-3.5 animate-spin" aria-hidden />
        {t("measuring")}
      </span>
    );
  }

  const again = (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button variant="ghost" size="icon-xs" onClick={measureAgain} disabled={loading} aria-label={t("measureAgain")}>
          {loading ? <Loader2 className="size-3.5 animate-spin" aria-hidden /> : <RefreshCw className="size-3.5" aria-hidden />}
        </Button>
      </TooltipTrigger>
      <TooltipContent>{t("measureAgain")}</TooltipContent>
    </Tooltip>
  );

  if (error) {
    return (
      <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1" role="alert">
        <TriangleAlert className="size-3.5 shrink-0 text-warning" aria-hidden />
        {/* The generic timeout text points at Backups, which means nothing here. */}
        <span>{error?.response?.data?.code === "server_operation_timed_out" ? t("tooBig") : apiMessage(error, t("failed"))}</span>
        <Button variant="outline" size="xs" onClick={measureAgain} disabled={loading}>
          {loading ? <Loader2 className="size-3 animate-spin" aria-hidden /> : null}
          {t("tryAgain")}
        </Button>
      </span>
    );
  }

  if (!data) return null;
  const at = parseApiWallClock(data.measured_at);
  // Clock skew must not read as "in 5 seconds".
  const ago = at ? format.relativeTime(Math.min(at.getTime(), now.getTime()), now) : null;
  return (
    <span className="inline-flex items-center gap-1">
      <span>{data.complete ? (ago ? t("measured", { ago }) : t("measuredNoTime")) : t("partial")}</span>
      {again}
    </span>
  );
}
