"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ChevronDown, Info, Loader2, Lock, SearchX, TriangleAlert, Puzzle } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { setPhpExtension } from "@/lib/api/php";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { FILTER_TOGGLE_ITEM } from "@/lib/theme/filter-toggle";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";
import { cn } from "@/lib/utils";
import { HoverHint } from "@/components/ui/hover-hint";

function driftOf(extension) {
  const sapis = Object.entries(extension.sapis ?? {});
  if (sapis.length < 2) return null;
  const on = sapis.filter(([, value]) => value).map(([name]) => name);
  if (on.length === 0 || on.length === sapis.length) return null;

  if (on.includes("fpm") && !on.includes("cli")) return "driftWebOnly";
  if (on.includes("cli") && !on.includes("fpm")) return "driftCliOnly";
  return "driftPartial";
}

export function ExtensionsCard({ version, extensions, panelRequired = [], toggleSupported = true, canManage }) {
  const t = useTranslations("php");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState("all");
  const [pending, setPending] = useState(null);

  const builtins = extensions.filter((extension) => extension.builtin);
  const changeable = extensions.filter((extension) => !extension.builtin);

  const onCount = changeable.filter((extension) => extension.enabled).length;

  const describe = (extension) =>
    t.has(`extensionInfo.${extension.name}`) ? t(`extensionInfo.${extension.name}`) : (extension.summary ?? null);

  const term = query.trim().toLowerCase();
  const matched = changeable
    .filter((extension) =>
      filter === "all" ? true : filter === "on" ? extension.enabled : !extension.enabled,
    )
    .filter((extension) => {
      if (!term) return true;
      if (extension.name.toLowerCase().includes(term)) return true;
      if (extension.modules.some((module) => module.toLowerCase().includes(term))) return true;
      return Boolean(describe(extension)?.toLowerCase().includes(term));
    });

  const shown = matched;

  const builtinMatch = term
    ? builtins.find((extension) => extension.name.toLowerCase().includes(term))
    : null;

  async function toggle(extension) {
    if (pending) return;
    const next = !extension.enabled;
    setPending({ name: extension.name, on: next });
    try {
      const response = await setPhpExtension(version, extension.name, next);
      await refreshAndWait();
      toast.success(
        response.status === 202
          ? t("extensions.installing", { name: extension.name })
          : next
            ? t("extensions.enabled", { name: extension.name })
            : t("extensions.disabled", { name: extension.name }),
      );
    } catch (error) {
      toast.error(apiMessage(error, t("extensions.failed")));
      router.refresh();
    } finally {
      setPending(null);
    }
  }

  return (
    <Card className="@container/ext">
      <CardHeader>
        <CardTitle icon={Puzzle}>{t("extensions.title")}</CardTitle>
        <CardDescription>
          {t("extensions.summary", { on: onCount, total: changeable.length })}
        </CardDescription>
        {!toggleSupported ? (
          <p className="flex items-start gap-2 pt-1 text-xs text-muted-foreground">
            <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            <span>{t("extensions.noToggleNote")}</span>
          </p>
        ) : null}
      </CardHeader>

      <CardContent className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <LocalSearchInput
            value={query}
            onChange={setQuery}
            placeholder={t("extensions.search")}
            className="sm:max-w-64"
          />
          <ToggleGroup
            type="single"
            value={filter}
            onValueChange={(next) => {
              if (!next) return;
              setFilter(next);
            }}
            className="flex-wrap gap-1"
          >
            <ToggleGroupItem value="all" className={FILTER_TOGGLE_ITEM}>
              {t("extensions.filterAll")}
            </ToggleGroupItem>
            <ToggleGroupItem value="on" className={FILTER_TOGGLE_ITEM}>
              {t("extensions.filterOn")}
            </ToggleGroupItem>
            <ToggleGroupItem value="off" className={FILTER_TOGGLE_ITEM}>
              {t("extensions.filterOff")}
            </ToggleGroupItem>
          </ToggleGroup>
        </div>

        {shown.length === 0 ? (
          <EmptyState
            compact
            icon={SearchX}
            subject={Puzzle}
            title={
              builtinMatch
                ? t("extensions.noMatchesBuiltin", { name: builtinMatch.name })
                : t("extensions.noMatches")
            }
          />
        ) : (
          <div className="relative -mx-(--card-spacing)">
            <div className="max-h-[32rem] overflow-y-auto border-y">
            <ul aria-label={t("extensions.title")} className="-mr-px -mb-px grid grid-cols-1 @xl/ext:grid-cols-2 @6xl/ext:grid-cols-3">
                {shown.map((extension) => {
                  const required = panelRequired.includes(extension.name);
                  const installing = extension.status === "installing";
                  // One at a time: each switch runs apt, and a second run fails on the lock.
                  const reason = !canManage
                    ? DENIED
                    : required
                      ? t("extensions.panelNeeds")
                      : installing
                        ? t("extensions.waitForOther", { name: extension.name })
                        : pending && pending.name !== extension.name
                          ? t("extensions.waitForOther", { name: pending.name })
                          : null;

                  return (
                    <li key={extension.name} className="flex items-center justify-between gap-4 border-r border-b px-4 py-2.5">
                      <div className="min-w-0 flex-1">
                        <span className="flex min-h-9 flex-col justify-center">
                        <span className="font-mono text-sm font-medium">{extension.name}</span>

                        {describe(extension) ? (
                          <span className="block text-xs whitespace-normal text-muted-foreground">
                            {describe(extension)}
                          </span>
                        ) : null}

                        {extension.status === "installing" || extension.status === "failed" ? (
                          <span className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                            {extension.status === "installing" ? (
                              <Loader2 className="size-3 shrink-0 animate-spin" />
                            ) : null}
                            {extension.status === "failed" && extension.message ? (
                              <span className="text-destructive">
                                {extension.message}
                                {extension.reference ? (
                                  <span className="ml-1.5 font-mono whitespace-nowrap text-muted-foreground">
                                    {extension.reference}
                                  </span>
                                ) : null}
                              </span>
                            ) : (
                              <span className="truncate font-mono">
                                {extension.queued
                                  ? t("versions.statusQueued")
                                  : extension.output?.trimEnd().split("\n").pop() ||
                                  (extension.current_step
                                    ? t(`versions.steps.${extension.current_step}`)
                                    : t("extensions.installingShort"))}
                              </span>
                            )}
                          </span>
                        ) : null}
                        </span>
                      </div>

                      <div className="shrink-0 text-right">
                        {!toggleSupported && extension.installed ? (
                          <span className="text-xs text-muted-foreground">{t("extensions.alwaysOn")}</span>
                        ) : !toggleSupported ? (
                          <ReasonTooltip reason={reason}>
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              disabled={Boolean(reason) || pending?.name === extension.name || extension.status === "installing"}
                              onClick={() => toggle(extension)}
                              aria-busy={pending?.name === extension.name || undefined}
                            >
                              {pending?.name === extension.name || extension.status === "installing" ? (
                                <Loader2 className="size-3.5 animate-spin" aria-hidden />
                              ) : null}
                              {pending?.name === extension.name || extension.status === "installing"
                                ? t("extensions.installingNow")
                                : t("extensions.install")}
                            </Button>
                          </ReasonTooltip>
                        ) : (
                        <span className="flex items-center justify-end gap-2">
                          {pending?.name === extension.name ? (
                            <span className="flex min-w-0 items-center gap-1.5 text-xs text-foreground">
                              <Loader2 className="size-4 shrink-0 animate-spin" aria-hidden />
                              <span className="hidden truncate sm:inline">
                                {pending.on ? t("extensions.enabling") : t("extensions.disabling")}
                              </span>
                            </span>
                          ) : null}
                          {required ? (
                            <HoverHint content={t("extensions.panelNeeds")}><span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                              <Lock className="size-3.5 shrink-0" aria-hidden />
                              {t("extensions.panelNeedsShort")}
                            </span></HoverHint>
                          ) : (
                            <>
                              {pending?.name === extension.name ? null : (
                                <span aria-hidden className={cn("min-w-7 text-right text-xs font-medium whitespace-nowrap", installing ? "text-muted-foreground" : extension.enabled ? "text-primary" : "text-muted-foreground")}>
                                  {installing
                                    ? t("extensions.installingNow")
                                    : extension.enabled
                                      ? t("extensions.stateOn")
                                      : t("extensions.stateOff")}
                                </span>
                              )}
                              {canManage ? (
                                <ReasonTooltip reason={reason}>
                                  <Switch
                                    size="lg"
                                    checked={extension.enabled}
                                    onCheckedChange={() => toggle(extension)}
                                    disabled={Boolean(reason) || pending?.name === extension.name}
                                    aria-label={t("extensions.toggle", { name: extension.name })}
                                    aria-busy={pending?.name === extension.name || undefined}
                                  />
                                </ReasonTooltip>
                              ) : null}
                            </>
                          )}
                        </span>
                        )}

                        {driftOf(extension) ? (
                          <span className="mt-1 flex items-center justify-end gap-1.5 text-xs text-warning">
                            <TriangleAlert className="size-3.5 shrink-0" />
                            {t(`extensions.${driftOf(extension)}`)}
                          </span>
                        ) : null}
                      </div>
                    </li>
                  );
                })}
            </ul>
            </div>
            <div className="pointer-events-none absolute inset-x-0 bottom-px h-8 bg-gradient-to-t from-card to-transparent" />
          </div>
        )}

        {builtins.length > 0 ? (
          <details className="group rounded-lg border">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2 text-sm text-muted-foreground hover:text-foreground">
              <span>{t("extensions.builtinTitle", { count: builtins.length })}</span>
              <ChevronDown className="size-4 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="border-t px-3 py-2">
              <p className="pb-2 text-xs text-muted-foreground">{t("extensions.builtinBody")}</p>
              <ul className="flex flex-wrap gap-1.5">
                {builtins.map((extension) => (
                  <li
                    key={extension.name}
                    className="rounded-md bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground"
                  >
                    {extension.name}
                  </li>
                ))}
              </ul>
            </div>
          </details>
        ) : null}
      </CardContent>
    </Card>
  );
}
