"use client";

import { useState, useCallback } from "react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { UserRound, ShieldCheck, History, TriangleAlert, SearchX } from "lucide-react";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { Button } from "@/components/ui/button";
import { ActivityToolbar } from "@/components/activity/activity-toolbar";
import { typesForScope, actionsForScope } from "@/lib/activity/labels";
import { useSetQuery } from "@/hooks/use-set-query";
import { ProfileForm } from "@/components/account/profile-form";
import { ChangePasswordForm } from "@/components/account/change-password-form";
import { AccountActivity } from "@/components/account/account-activity";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";

const TABS = ["profile", "security", "activity"];
// !h-auto overrides shadcn TabsList's hard-coded h-8, so py padding takes effect.
const TRIGGER =
  "!h-auto flex-none gap-2 px-4 py-2 hover:bg-background/60 data-active:shadow-sm";

export function AccountTabs({ user, entries, meta, filters, isFiltered, activityFailed }) {
  const t = useTranslations("account");
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const initial = TABS.includes(searchParams.get("tab"))
    ? searchParams.get("tab")
    : "profile";
  const [tab, setTab] = useState(initial);
  const [profileDirty, setProfileDirty] = useState(false);
  const [pendingTab, setPendingTab] = useState(null);

  const applyTab = useCallback((next) => {
    setTab(next);
    const params = new URLSearchParams(window.location.search);
    params.set("tab", next);
    window.history.replaceState(null, "", `?${params.toString()}`);
  }, []);

  const onChange = useCallback(
    (next) => {
      // Confirm before leaving Profile with unsaved edits (our dialog, not the
      // browser's — a beforeunload prompt can't be styled).
      if (tab === "profile" && next !== "profile" && profileDirty) {
        setPendingTab(next);
        return;
      }
      applyTab(next);
    },
    [tab, profileDirty, applyTab],
  );

  return (
    <Tabs value={tab} onValueChange={onChange} className="gap-6">
      {/* Scrolls rather than wraps, same as the Settings tab bar: a bar that
          reflows to two rows stops reading as one control. ScrollFade is what
          says there is more to the side. */}
      <ScrollFade className="-mx-1 px-1 pb-1">
        <TabsList className="!h-auto w-fit gap-1 p-1">
          <TabsTrigger value="profile" className={TRIGGER}>
            <UserRound className="size-4" />
            {t("tabs.profile")}
          </TabsTrigger>
          <TabsTrigger value="security" className={TRIGGER}>
            <ShieldCheck className="size-4" />
            {t("tabs.security")}
          </TabsTrigger>
          <TabsTrigger value="activity" className={TRIGGER}>
            <History className="size-4" />
            {t("tabs.activity")}
          </TabsTrigger>
        </TabsList>
      </ScrollFade>

      <TabsContent value="profile">
        <ProfileForm user={user} onDirtyChange={setProfileDirty} />
      </TabsContent>

      <TabsContent value="security">
        <ChangePasswordForm />
      </TabsContent>

      <TabsContent value="activity">
        <NavTransitionProvider>
          <div className="space-y-4">
            {/* extraQuery keeps ?tab=activity on the URL: the tab is written
                with history.replaceState, which the router doesn't see, so a
                filter navigation would otherwise drop it. */}
            {/* This tab is account rows only, but the filters endpoint spans
                both scopes — unfiltered it would offer "Firewall" here. */}
            <ActivityToolbar
              types={typesForScope(filters.types, "account")}
              actions={actionsForScope(filters.actions, filters.types, "account")}
              extraQuery={{ tab: "activity" }}
            />
            {/* Checked before "no entries": with filters applied a failure
                would otherwise read as "no matches", which is a wrong answer
                rather than an error. */}
            {activityFailed ? (
              <LoadFailed />
            ) : entries.length ? (
              <>
                <AccountActivity data={entries} />
                <DataTablePagination meta={meta} />
              </>
            ) : isFiltered ? (
              <EmptyState
                icon={SearchX}
                title={t("activity.filteredTitle")}
                description={t("activity.filteredDesc")}
                action={
                  <Button
                    variant="outline"
                    onClick={() =>
                      setQuery(
                        {
                          search: undefined,
                          type: undefined,
                          action: undefined,
                          tab: "activity",
                        },
                        { resetPage: true },
                      )
                    }
                  >
                    {t("activity.clearFilters")}
                  </Button>
                }
              />
            ) : (
              <EmptyState
                icon={History}
                title={t("activity.empty")}
                description={t("activity.emptyDesc")}
              />
            )}
          </div>
        </NavTransitionProvider>
      </TabsContent>

      <ConfirmDialog
        open={pendingTab !== null}
        onOpenChange={(o) => !o && setPendingTab(null)}
        icon={TriangleAlert}
        tone="destructive"
        title={t("profile.unsavedTitle")}
        description={t("profile.unsavedDesc")}
        cancelLabel={t("profile.unsavedKeep")}
        confirmLabel={t("profile.unsavedDiscard")}
        onConfirm={() => {
          setProfileDirty(false);
          applyTab(pendingTab);
          setPendingTab(null);
        }}
      />
    </Tabs>
  );
}
