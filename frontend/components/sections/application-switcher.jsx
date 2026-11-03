import Link from "@/components/ui/app-link";
import { useEffect, useRef, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Check, Loader2, Search } from "lucide-react";
import { searchApplications, getApplicationNavCatalog } from "@/lib/api/applications";
import { switchTarget } from "@/lib/applications/switch-target";
import { switcherRows } from "@/lib/applications/switcher-list";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { ApplicationStatusDot } from "@/components/applications/application-status-badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useUnsaved } from "@/components/ui/unsaved-guard";
import { useSidebar } from "@/components/ui/sidebar";
import { cn } from "@/lib/utils";

// The sidebar's application card opens this (tester request, 2 Nov): another application without
// going back to the list. The list is one searched page, not the list page's pagination: in a
// dropdown you type the name you want. Fetched only when opened, so pages do not pay for it.
export function ApplicationSwitcher({ application, children, ...triggerProps }) {
  const t = useTranslations("common.applicationContext");
  const pathname = usePathname();
  const router = useRouter();
  const { guardNavigation } = useUnsaved();
  const { isMobile, setOpenMobile } = useSidebar();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  // `query` is what the list answers, so a result never shows under a search it was not for.
  const [state, setState] = useState({ status: "idle", applications: [], total: 0, query: null });
  const [going, setGoing] = useState(null);
  const listRef = useRef(null);
  const [attempt, setAttempt] = useState(0);

  // A short pause after each key, so typing "shop" is one request, not four.
  useEffect(() => {
    if (!open) return undefined;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
      setState((previous) => ({ ...previous, status: "loading" }));
      try {
        const { data } = await searchApplications(query.trim(), { signal: controller.signal });
        setState({ status: "ready", applications: data?.applications ?? [], total: data?.meta?.total ?? 0, query: query.trim() });
      } catch {
        if (controller.signal.aborted) return;
        setState({ status: "failed", applications: [], total: 0, query: query.trim() });
      }
    }, query ? 250 : 0);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [open, query, attempt]);

  function close() {
    setOpen(false);
    setQuery("");
  }

  async function pick(target) {
    // One switch at a time: a second click while the first loads is ignored.
    if (going !== null) return;
    if (String(target.id) === String(application.id)) return close();
    setGoing(target.id);
    // The other site's own menu says whether it has this section; if that cannot be read,
    // its dashboard is still right.
    const items = await getApplicationNavCatalog(target.id).catch(() => []);
    const href = switchTarget(pathname, application.id, target.id, items);
    setGoing(null);
    close();
    const leave = () => isMobile && setOpenMobile(false);
    if (!guardNavigation(href, leave)) {
      leave();
      router.push(href);
    }
  }

  // Arrow keys move between rows, from the search box too.
  function onKeyDown(event) {
    if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
    const rows = [...(listRef.current?.querySelectorAll("[data-switcher-row]") ?? [])];
    if (!rows.length) return;
    event.preventDefault();
    const index = rows.indexOf(document.activeElement);
    const next = event.key === "ArrowDown" ? (index + 1) % rows.length : (index <= 0 ? rows.length : index) - 1;
    rows[next]?.focus();
  }

  const visible = switcherRows(state.applications, application, state.query);
  const shown = visible.length;
  // From the first key until the answer arrives, the pause included: without it the old list just
  // sat there and nothing said a search was running (Krishna, 3 Nov).
  const searching = open && (state.status === "loading" || state.query !== query.trim());

  return (
    <Popover open={open} onOpenChange={(next) => (next ? setOpen(true) : close())}>
      <PopoverTrigger asChild>
        {/* Props from the sidebar's menu button (its look and data attributes) land on the trigger. */}
        <button type="button" {...triggerProps}>
          {children}
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" sideOffset={6} className="w-(--radix-popover-trigger-width) min-w-72 gap-0 p-0" onKeyDown={onKeyDown}>
        <div className="relative border-b p-2">
          {searching ? (
            <Loader2 className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 animate-spin text-muted-foreground" aria-hidden />
          ) : (
            <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          )}
          <Input
            autoFocus
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={t("searchPlaceholder")}
            aria-label={t("searchPlaceholder")}
            className="h-8 pl-8 text-sm"
          />
          {/* Said, not only shown, to a screen reader. */}
          <span className="sr-only" role="status" aria-live="polite">
            {searching ? t("searching") : state.status === "ready" ? t("found", { count: shown }) : ""}
          </span>
        </div>

        {/* The previous answer stays while the next loads, dimmed, so the list does not jump. */}
        <div ref={listRef} className={cn("max-h-80 overflow-y-auto p-1 transition-opacity", searching && shown > 0 && "opacity-60")} role="list" aria-busy={searching || undefined}>
          {state.status === "failed" ? (
            <div className="flex flex-col items-start gap-2 px-2 py-3 text-sm">
              <p className="text-muted-foreground">{t("loadFailed")}</p>
              <Button type="button" variant="outline" size="xs" onClick={() => setAttempt((n) => n + 1)}>
                {t("retry")}
              </Button>
            </div>
          ) : searching && shown === 0 ? (
            <p className="flex items-center gap-2 px-2 py-3 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" aria-hidden />
              {t("loading")}
            </p>
          ) : shown === 0 && state.status === "ready" ? (
            <p className="px-2 py-3 text-sm text-muted-foreground">{t("noMatch", { query: state.query ?? "" })}</p>
          ) : (
            visible.map((item) => {
              const current = String(item.id) === String(application.id);
              return (
                <div key={item.id} role="listitem">
                  <button
                    type="button"
                    data-switcher-row
                    aria-current={current ? "page" : undefined}
                    aria-busy={going === item.id || undefined}
                    onClick={() => pick(item)}
                    className="flex w-full min-w-0 items-center gap-2.5 rounded-md px-2 py-1.5 text-left outline-none hover:bg-muted focus-visible:bg-muted focus-visible:ring-2 focus-visible:ring-ring aria-busy:opacity-60"
                  >
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-md border bg-card">
                      <SiteTypeLogo name={item.site_type} size="h-4 w-4" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-medium text-foreground" title={item.name}>{item.name}</span>
                      <span className="block truncate font-mono text-xs text-muted-foreground" title={item.domain}>{item.domain}</span>
                    </span>
                    {going === item.id ? (
                      <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" aria-hidden />
                    ) : current ? (
                      <Check className="size-4 shrink-0 text-primary" aria-hidden />
                    ) : (
                      <ApplicationStatusDot application={item} className="shrink-0" labelClassName="sr-only" />
                    )}
                  </button>
                </div>
              );
            })
          )}
        </div>

        {/* Wraps instead of truncating: beside the link, "type to search" was cut to "ty…". */}
        <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t px-3 py-2 text-xs text-muted-foreground">
          {state.status === "ready" && state.total > shown ? <span>{t("showingSome", { shown, total: state.total })}</span> : null}
          <Link href="/applications" prefetch={false} onClick={close} className="ml-auto shrink-0 font-medium text-primary hover:underline">
            {t("viewAll")}
          </Link>
        </div>
      </PopoverContent>
    </Popover>
  );
}
