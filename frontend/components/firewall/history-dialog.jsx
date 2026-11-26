import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { History, Loader2, ExternalLink } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { getMyActivityByType, getServerActivityByType } from "@/lib/api/activity-log";
import { myActivityResponseSchema } from "@/lib/schemas/account";
import { activityResponseSchema } from "@/lib/schemas/activity";
import { humanizeActivity } from "@/lib/activity-log/labels";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Pager } from "@/components/data-table/pager";
import { PerPageSelect } from "@/components/data-table/per-page-select";
import { readPerPage } from "@/lib/tables/per-page-cookie";
import { apiMessage } from "@/lib/api/error-message";
import { PER_PAGE_OPTIONS } from "@/lib/schemas/user";

export function HistoryDialog({ everyone = false, variant = "outline" }) {
  const t = useTranslations("firewall");
  const paginationT = useTranslations("pagination");
  const tActivity = useTranslations("activity");
  const [open, setOpen] = useState(false);
  const [perPage, setPerPage] = useState(10);
  const [state, setState] = useState({
    loading: false,
    failed: false,
    entries: [],
    meta: null,
    status: null,
  });

  async function load(page = 1, requestedPerPage = perPage) {
    setState({ loading: true, failed: false, entries: [], meta: null, status: null });
    try {
      const fetchHistory = everyone ? getServerActivityByType : getMyActivityByType;
      const response = await fetchHistory("firewall", {
        page,
        perPage: requestedPerPage,
      });
      const parsed = (everyone ? activityResponseSchema : myActivityResponseSchema).safeParse(
        response.data,
      );
      if (!parsed.success) {
        setState({ loading: false, failed: true, entries: [], meta: null, status: null, failure: "shape" });
        return;
      }
      setState({
        loading: false,
        failed: false,
        entries: parsed.data.activity_log,
        meta: parsed.data.meta,
        status: null,
      });
    } catch (error) {
      setState({
        loading: false,
        failed: true,
        entries: [],
        meta: null,
        status: error?.response?.status ?? null,
        message: apiMessage(error, null),
      });
    }
  }

  return (
    <>
      <Button
        variant={variant}
        onClick={() => {
          setOpen(true);
          const remembered = readPerPage();
          setPerPage(remembered);
          load(1, remembered);
        }}
      >
        <History className="size-4" />
        {t("history.action")}
      </Button>

      <FormModal
        open={open}
        onOpenChange={setOpen}
        icon={History}
        title={t("history.title")}
        description={everyone ? t("history.descriptionEveryone") : t("history.description")}
        footer={
          <>
            {everyone ? (
              <Button variant="outline" asChild className="mr-auto">
                <Link href="/activity-log?type=firewall">
                  <ExternalLink className="size-4" />
                  {t("history.openLog")}
                </Link>
              </Button>
            ) : null}
            <Button variant="outline" onClick={() => setOpen(false)}>
              {t("common.close")}
            </Button>
          </>
        }
      >
        {state.loading ? (
          <p className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" />
            {t("history.loading")}
          </p>
        ) : state.failed ? (
          <LoadFailed
            description={everyone ? t("history.failedEveryone") : t("history.failed")}
            status={state.status}
            failure={state.failure ?? null}
            message={state.message ?? null}
          />
        ) : state.entries.length === 0 ? (
          <EmptyState compact icon={History} badge={null} title={everyone ? t("history.emptyEveryone") : t("history.empty")} />
        ) : (
          <div className="space-y-4">
            <ul className="divide-y">
              {state.entries.map((entry) => (
                <li key={entry.id} className="flex items-start justify-between gap-3 py-2.5">
                  <div className="min-w-0 space-y-1">
                    <p className="text-sm leading-snug">
                      {entry.description || humanizeActivity(entry.action)}
                    </p>
                  </div>
                  <span className="shrink-0 space-y-0.5 text-right text-xs text-muted-foreground">
                    {everyone ? (
                      <span className="block whitespace-nowrap text-foreground">
                        {entry.user ? `@${entry.user.username}` : tActivity("system")}
                      </span>
                    ) : null}
                    <span className="block whitespace-nowrap">
                      {entry.created_at_human || entry.created_at || "—"}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
            {state.meta?.last_page > 1 || state.meta?.total > PER_PAGE_OPTIONS[0] ? (
              <div className="flex flex-col gap-3">
                <PerPageSelect
                  label={paginationT("perPage")}
                  value={String(perPage)}
                  onValueChange={(value) => {
                    const nextPerPage = Number(value);
                    setPerPage(nextPerPage);
                    load(1, nextPerPage);
                  }}
                />
                {state.meta.last_page > 1 ? (
                  <div className="self-end">
                    <Pager
                      page={state.meta.current_page}
                      lastPage={state.meta.last_page}
                      total={state.meta.total}
                      pending={state.loading}
                      onPageChange={load}
                    />
                  </div>
                ) : null}
              </div>
            ) : null}
          </div>
        )}
      </FormModal>
    </>
  );
}
