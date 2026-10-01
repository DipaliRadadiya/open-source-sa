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

// Ignores are permanent and silent, so this is the only place that state is visible.
export function IgnoredSheet({ ignores, canManage, pendingKeys = [], onUnignore }) {
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
                      {/* Wrapped: a disabled button stops receiving pointer
                          events, and it is disabled while pending. */}
                      <TooltipTrigger asChild>
                        <span className="inline-flex shrink-0">
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            disabled={pendingKeys.includes(ignoreKey(ignore))}
                            aria-label={t("ignored.restore", { name: ignore.resource_key })}
                            onClick={() => onUnignore(ignore)}
                          >
                            {/* A spinner, since disabling alone is not feedback. */}
                            {pendingKeys.includes(ignoreKey(ignore)) ? (
                              <Loader2 className="size-4 animate-spin" aria-hidden />
                            ) : (
                              <Undo2 className="size-4" aria-hidden />
                            )}
                          </Button>
                        </span>
                      </TooltipTrigger>
                      <TooltipContent>
                        {pendingKeys.includes(ignoreKey(ignore))
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
