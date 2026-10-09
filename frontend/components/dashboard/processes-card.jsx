"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ChevronUp } from "lucide-react";
import { cn } from "@/lib/utils";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { ProcessTable } from "@/components/dashboard/process-table";

const PREVIEW_COUNT = 3;

// The full list expands in place: a side sheet was too narrow for the command column.
function ProcessesCardInner({ data, failed, total, canManage }) {
  const t = useTranslations("serverDashboard");
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");

  return (
    // Same chrome as every other card on this page.
    <Card className={cn("[--card-spacing:--spacing(5)]", PANEL_CARD)}>
      <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <CardTitle as="h2" className="flex items-center gap-2">
            {t("processes.title")}
          </CardTitle>
          <CardDescription>{t("processes.topDescription")}</CardDescription>
        </div>
        <div className="flex items-center gap-2">
          {/* Search only in the expanded view; three rows need no filter. */}
          {open ? (
            <>
              <div className="w-full sm:w-56">
                <LocalSearchInput
                  value={query}
                  onChange={setQuery}
                  placeholder={t("processes.search")}
                />
              </div>
              <RefreshButton />
            </>
          ) : null}
          {/* Default size, not sm, to match the h-9 search and refresh controls. */}
          <Button
            variant="outline"
            onClick={() => setOpen((prev) => !prev)}
            aria-expanded={open}
          >
            {open ? (
              <ChevronUp className="size-4" />
            ) : (
              <ChevronDown className="size-4" />
            )}
            {open ? t("processes.showLess") : t("processes.viewAll")}
          </Button>
        </div>
      </CardHeader>

      <CardContent>
        {/* ProcessTable handles the failed state itself. */}
        <ProcessTable
          data={data}
          query={query}
          failed={failed}
          total={total}
          canManage={canManage}
          limit={open ? null : PREVIEW_COUNT}
        />
      </CardContent>
    </Card>
  );
}

export function ProcessesCard({ data, failed, total, canManage }) {
  return (
    <NavTransitionProvider>
      <ProcessesCardInner data={data} failed={failed} total={total} canManage={canManage} />
    </NavTransitionProvider>
  );
}
