"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ChevronDown, Info, Loader2, SearchX, TriangleAlert } from "lucide-react";
import { setPhpExtension } from "@/lib/api/php";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";

/**
 * Which SAPIs disagree, if any. `sapis` only drifts after a manual `phpenmod`
 * or `phpdismod`; the panel's toggle writes every SAPI. Returns a translation
 * key, or null when everything agrees.
 */
function driftOf(extension) {
  const sapis = Object.entries(extension.sapis ?? {});
  if (sapis.length < 2) return null;
  const on = sapis.filter(([, value]) => value).map(([name]) => name);
  if (on.length === 0 || on.length === sapis.length) return null;

  if (on.includes("fpm") && !on.includes("cli")) return "driftWebOnly";
  if (on.includes("cli") && !on.includes("fpm")) return "driftCliOnly";
  return "driftPartial";
}

/**
 * One switch per extension. A row is a PACKAGE, not a module (`php8.4-mysql`
 * provides mysqli, mysqlnd and pdo_mysql), and turning it on installs it if
 * needed.
 */
export function ExtensionsCard({ version, extensions, panelRequired = [], toggleSupported = true, canManage }) {
  const t = useTranslations("php");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState("all");
  // Which extension is mid-request and in which direction, so the row can say
  // "Enabling…" or "Disabling…".
  const [pending, setPending] = useState(null);

  // Built-ins are listed separately: they can never be switched and have no
  // description.
  const builtins = extensions.filter((extension) => extension.builtin);
  const changeable = extensions.filter((extension) => !extension.builtin);

  // Counted over what can actually change (excludes built-ins).
  const onCount = changeable.filter((extension) => extension.enabled).length;

  const term = query.trim().toLowerCase();
  const matched = changeable
    .filter((extension) =>
      filter === "all" ? true : filter === "on" ? extension.enabled : !extension.enabled,
    )
    .filter((extension) => {
      if (!term) return true;
      if (extension.name.toLowerCase().includes(term)) return true;
      if (extension.modules.some((module) => module.toLowerCase().includes(term))) return true;
      // Descriptions are searched too: users know the purpose, not the package name.
      const key = `extensionInfo.${extension.name}`;
      return t.has(key) && t(key).toLowerCase().includes(term);
    });

  // No pager: the list scrolls inside a fixed height, and search and filter
  // already narrow it.
  const shown = matched;

  // Did the search only miss because the answer is compiled into PHP?
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
      // 202 means apt is queued (minutes), so the message says so.
      toast.success(
        response.status === 202
          ? t("extensions.installing", { name: extension.name })
          : next
            ? t("extensions.enabled", { name: extension.name })
            : t("extensions.disabled", { name: extension.name }),
      );
    } catch (error) {
      // apiMessage keeps the reference, which support may ask for.
      toast.error(apiMessage(error, t("extensions.failed")));
      // A 500 can mean "changed, but PHP was not reloaded", so the switch must
      // re-read what is on disk rather than keep showing the old state.
      router.refresh();
    } finally {
      setPending(null);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold">{t("extensions.title")}</CardTitle>
        <CardDescription>
          {t("extensions.summary", { on: onCount, total: changeable.length })}
        </CardDescription>
        {/* Said once for the list: on OpenLiteSpeed the rows have no switch. */}
        {!toggleSupported ? (
          <p className="flex items-start gap-2 pt-1 text-xs text-muted-foreground">
            <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            <span>{t("extensions.noToggleNote")}</span>
          </p>
        ) : null}
      </CardHeader>

      <CardContent className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          {/* The shared search box, with icon and clear button. */}
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
            variant="outline"
            // flex-wrap: separate bordered buttons, so they wrap rather than
            // overflow in longer locales.
            className="flex-wrap gap-1"
          >
            <ToggleGroupItem value="all" className="px-3">
              {t("extensions.filterAll")}
            </ToggleGroupItem>
            <ToggleGroupItem value="on" className="px-3">
              {t("extensions.filterOn")}
            </ToggleGroupItem>
            <ToggleGroupItem value="off" className="px-3">
              {t("extensions.filterOff")}
            </ToggleGroupItem>
          </ToggleGroup>
        </div>

        {shown.length === 0 ? (
          <p className="flex items-center justify-center gap-2 py-8 text-center text-sm text-muted-foreground">
            <SearchX className="size-4 shrink-0" />
            {/* A search that only matches a built-in names it instead of
                saying "no extensions match". */}
            {builtinMatch
              ? t("extensions.noMatchesBuiltin", { name: builtinMatch.name })
              : t("extensions.noMatches")}
          </p>
        ) : (
          <div className="relative">
            <div className="max-h-[32rem] overflow-y-auto rounded-lg border">
            <Table>
              <TableHeader>
                {/* Header labels the column of switches and badges. */}
                <TableRow className="sticky top-0 z-10 bg-muted hover:bg-muted">
                  <TableHead>{t("extensions.colName")}</TableHead>
                  {/* Narrow on a phone so the switches stay on screen. */}
                  <TableHead className="w-24 text-right sm:w-56">
                    {t("extensions.colStatus")}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {shown.map((extension) => {
                  const required = panelRequired.includes(extension.name);
                  // One at a time: each switch runs apt and restarts PHP, and a
                  // second apt run would fail on the lock.
                  const reason = !canManage
                    ? t("noPermission")
                    : required
                      ? t("extensions.panelNeeds")
                      : pending && pending.name !== extension.name
                        ? t("extensions.waitForOther", { name: pending.name })
                        : null;

                  return (
                    <TableRow key={extension.name}>
                      {/* min-h keeps every row the same height, with or
                          without a description. */}
                      <TableCell className="max-w-0">
                        <span className="flex min-h-9 flex-col justify-center">
                        <span className="font-mono text-sm font-medium">{extension.name}</span>

                        {/* Only extensions with real copy get a line. Wraps
                            instead of truncating (unreadable on touch); max-w-0
                            keeps the column from widening the table,
                            whitespace-normal undoes TableCell's nowrap. */}
                        {t.has(`extensionInfo.${extension.name}`) ? (
                          <span className="block text-xs whitespace-normal text-muted-foreground">
                            {t(`extensionInfo.${extension.name}`)}
                          </span>
                        ) : null}

                        {/* apt's output while installing and after a failure,
                            since different failures need different fixes. */}
                        {extension.status === "installing" || extension.status === "failed" ? (
                          <span className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                            {extension.status === "installing" ? (
                              <Loader2 className="size-3 shrink-0 animate-spin" />
                            ) : null}
                            {extension.status === "failed" && extension.message ? (
                              // The server's sentence, not apt's last line: after
                              // `reload_failed` apt succeeded and its tail looks fine.
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
                                {extension.output?.trimEnd().split("\n").pop() ||
                                  (extension.current_step
                                    ? t(`versions.steps.${extension.current_step}`)
                                    : t("extensions.installingShort"))}
                              </span>
                            )}
                          </span>
                        ) : null}
                        </span>
                      </TableCell>

                      <TableCell className="text-right">
                        {/* Every row gets the same control in the same spot;
                            locked rows are disabled with the reason on hover.
                            A spinner shows while the request runs (enabling
                            restarts FPM). */}
                        {!toggleSupported && extension.installed ? (
                          // OpenLiteSpeed: installed means on, and it cannot be
                          // switched off.
                          <span className="text-xs text-muted-foreground">{t("extensions.alwaysOn")}</span>
                        ) : !toggleSupported ? (
                          // Not installed yet: installing still works, but a
                          // switch would promise an off that does not exist.
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
                              {/* The word as well as the spinner, which alone
                                  reads as part of the switch. Hidden on a phone,
                                  where the column is too narrow. */}
                              <span className="hidden truncate sm:inline">
                                {pending.on ? t("extensions.enabling") : t("extensions.disabling")}
                              </span>
                            </span>
                          ) : null}
                          <ReasonTooltip reason={reason}>
                            <Switch
                              checked={extension.enabled}
                              onCheckedChange={() => toggle(extension)}
                              disabled={Boolean(reason) || pending?.name === extension.name}
                              aria-label={t("extensions.toggle", { name: extension.name })}
                              aria-busy={pending?.name === extension.name || undefined}
                            />
                          </ReasonTooltip>
                        </span>
                        )}

                        {/* `enabled` is all-or-nothing, so a manual `phpdismod`
                            can show "off" while the extension is still live for
                            websites. */}
                        {driftOf(extension) ? (
                          <span className="mt-1 flex items-center justify-end gap-1.5 text-xs text-warning">
                            <TriangleAlert className="size-3.5 shrink-0" />
                            {t(`extensions.${driftOf(extension)}`)}
                          </span>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
            </div>
            {/* Fade so the cut-off last row reads as scrollable. */}
            <div className="pointer-events-none absolute inset-x-px bottom-px h-8 rounded-b-lg bg-gradient-to-t from-background to-transparent" />
          </div>
        )}

        {/* Built-ins: kept so search finds them, folded since nothing can change. */}
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
