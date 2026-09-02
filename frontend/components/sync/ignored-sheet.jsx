import { EyeOff, Loader2, Undo2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { ignoreKey } from "@/lib/server/sync-selection";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";

/**
 * Everything previously dismissed, and the way back.
 *
 * Of the products surveyed for this screen, not one lets you see what you
 * excluded from a discovery run or undo it — Zabbix buries exclusions in a
 * rule, PRTG in a template. Ours is worth surfacing because an ignore here is
 * permanent and silent: an ignored thing stops appearing in every later scan,
 * so a mis-click removes a site from the panel's view for good with nothing on
 * screen to say why. This is the only place that state is visible.
 */
export function IgnoredSheet({ ignores, canManage, pendingKey, onUnignore }) {
  const t = useTranslations("sync");

  return (
    <Sheet>
      <SheetTrigger asChild>
        <Button variant="ghost">
          <EyeOff className="size-4" aria-hidden />
          {t("ignored.trigger", { count: ignores.length })}
        </Button>
      </SheetTrigger>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{t("ignored.title")}</SheetTitle>
          <SheetDescription>{t("ignored.description")}</SheetDescription>
        </SheetHeader>

        <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-4">
          {ignores.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("ignored.empty")}</p>
          ) : (
            <ul className="divide-y">
              {ignores.map((ignore) => (
                <li key={ignore.id} className="flex items-start gap-3 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="font-mono text-sm break-all">{ignore.resource_key}</p>
                    <p className="text-xs text-muted-foreground">
                      {t(`types.${ignore.resource_type}`)}
                    </p>
                    {ignore.note ? (
                      <p className="mt-0.5 text-xs text-muted-foreground">{ignore.note}</p>
                    ) : null}
                  </div>
                  {canManage ? (
                    <Tooltip>
                      {/* Wrapped, because a disabled button stops receiving
                          pointer events — and this one is disabled exactly
                          while someone is waiting to know it is working. */}
                      <TooltipTrigger asChild>
                        <span className="inline-flex shrink-0">
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            disabled={pendingKey === ignoreKey(ignore)}
                            aria-label={t("ignored.restore", { name: ignore.resource_key })}
                            onClick={() => onUnignore(ignore)}
                          >
                            {/* Same gap as the results table: disabling on its
                                own is not feedback, because nothing on screen
                                changes. */}
                            {pendingKey === ignoreKey(ignore) ? (
                              <Loader2 className="size-4 animate-spin" aria-hidden />
                            ) : (
                              <Undo2 className="size-4" aria-hidden />
                            )}
                          </Button>
                        </span>
                      </TooltipTrigger>
                      <TooltipContent>
                        {pendingKey === ignoreKey(ignore)
                          ? t("results.working")
                          : t("results.restoreShort")}
                      </TooltipContent>
                    </Tooltip>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}
