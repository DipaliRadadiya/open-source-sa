"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ChevronUp, ListTree } from "lucide-react";
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
import { ProcessTable } from "@/components/server-dashboard/process-table";

const PREVIEW_COUNT = 3;

/**
 * The three heaviest processes on the page, the rest behind a button.
 *
 * The full table was the tallest block on the dashboard — 548px, more than a
 * third of the page — for the thing people need least often. It also put a
 * destructive action (stop a process) on the landing route, where a misclick
 * is cheapest to make.
 *
 * Three rows answer the dashboard's actual question — "what is using this
 * server?"; the full table expands in place when you ask for it. Both states
 * render the SAME table with the same columns, so expanding adds rows instead
 * of swapping one layout for another.
 *
 * It lived in a side sheet first. That was wrong twice over: the sheet caps at
 * 384px, so the command column — the one that says what a process actually is —
 * was pushed off-screen, and a five-column table wants the page width it can
 * only get inline.
 */
function ProcessesCardInner({ data, failed, canManage }) {
  const t = useTranslations("serverDashboard");
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <CardTitle className="flex items-center gap-2 text-lg font-semibold">
            <ListTree className="size-4 text-primary" />
            {t("processes.title")}
          </CardTitle>
          <CardDescription>{t("processes.topDescription")}</CardDescription>
        </div>
        <div className="flex items-center gap-2">
          {/* The search box only exists once the table does — filtering a list
              of three is a control looking for a job. */}
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
          <Button
            variant="outline"
            size="sm"
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
        {failed ? (
          <p className="text-sm text-muted-foreground">{t("processes.loadFailed")}</p>
        ) : (
          <ProcessTable
            data={data}
            query={query}
            failed={failed}
            canManage={canManage}
            limit={open ? null : PREVIEW_COUNT}
          />
        )}
      </CardContent>
    </Card>
  );
}

export function ProcessesCard({ data, failed, canManage }) {
  return (
    <NavTransitionProvider>
      <ProcessesCardInner data={data} failed={failed} canManage={canManage} />
    </NavTransitionProvider>
  );
}
