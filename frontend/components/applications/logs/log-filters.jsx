import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { SlidersHorizontal, X } from "lucide-react";
import { EMPTY_LOG_FILTERS, LOG_METHODS, logFiltersSchema } from "@/lib/schemas/application-log";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

// Radix Select cannot hold an empty value.
const ANY_METHOD = "any";

const FIELD_KEYS = ["ip", "status", "method", "url"];

export const activeFilterKeys = (filters) =>
  Object.keys(EMPTY_LOG_FILTERS).filter((key) => filters[key] !== "");

export const hasFieldFilters = (filters) => FIELD_KEYS.some((key) => filters[key] !== "");

// Applied on submit, not per keystroke: each change re-reads the log on the server.
export function LogFiltersButton({ filters, onApply, fieldFilters, fromAllowed, disabled }) {
  const t = useTranslations("applications.logs.filters");
  const [open, setOpen] = useState(false);
  const form = useForm({
    resolver: zodResolver(logFiltersSchema),
    defaultValues: filters,
  });
  const count = activeFilterKeys(filters).length;
  const label = count ? t("buttonActive", { count }) : t("button");

  function openChange(next) {
    // Opens on what is applied, not on an abandoned edit.
    if (next) form.reset(filters);
    setOpen(next);
  }

  function submit(values) {
    onApply({ ...values, status: values.status.toLowerCase(), method: values.method.toUpperCase() });
    setOpen(false);
  }

  if (!fieldFilters && !fromAllowed) return null;

  return (
    <Popover open={open} onOpenChange={openChange}>
      <IconTooltip label={label}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          disabled={disabled}
          aria-label={label}
          className={cn("relative size-7 rounded-md text-muted-foreground", count && "text-primary")}
        >
          <SlidersHorizontal className="size-4" />
          {count ? (
            <span className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-xs leading-none font-semibold tabular-nums text-primary-foreground">
              {count}
            </span>
          ) : null}
        </Button>
      </PopoverTrigger>
      </IconTooltip>
      <PopoverContent align="end" className="w-[min(22rem,calc(100vw-2rem))]">
        <Form {...form}>
          <form noValidate onSubmit={form.handleSubmit(submit)} className="grid gap-3">
            {fromAllowed ? (
              <FormField
                control={form.control}
                name="from"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t("from")}</FormLabel>
                    <FormControl>
                      <Input inputMode="numeric" placeholder={t("fromPlaceholder")} {...field} />
                    </FormControl>
                    <p className="text-xs text-muted-foreground">{t("fromHint")}</p>
                    <FormMessage />
                  </FormItem>
                )}
              />
            ) : null}

            {fieldFilters ? (
              <>
                <div className="grid grid-cols-2 gap-3">
                  <FormField
                    control={form.control}
                    name="status"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>{t("status")}</FormLabel>
                        <FormControl>
                          <Input placeholder={t("statusPlaceholder")} maxLength={3} {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="method"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>{t("method")}</FormLabel>
                        <Select
                          value={field.value || ANY_METHOD}
                          onValueChange={(v) => field.onChange(v === ANY_METHOD ? "" : v)}
                        >
                          <FormControl>
                            <SelectTrigger className="w-full">
                              <SelectValue />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent position="popper">
                            <SelectItem value={ANY_METHOD}>{t("methodAny")}</SelectItem>
                            {LOG_METHODS.map((method) => (
                              <SelectItem key={method} value={method}>
                                {method}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>
                <FormField
                  control={form.control}
                  name="ip"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t("ip")}</FormLabel>
                      <FormControl>
                        <Input placeholder={t("ipPlaceholder")} className="font-mono" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="url"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t("url")}</FormLabel>
                      <FormControl>
                        <Input placeholder={t("urlPlaceholder")} maxLength={500} className="font-mono" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </>
            ) : null}

            <div className="flex justify-end gap-2 pt-1">
              <Button type="button" variant="outline" onClick={() => form.reset(EMPTY_LOG_FILTERS)}>
                {t("reset")}
              </Button>
              <Button type="submit">{t("apply")}</Button>
            </div>
          </form>
        </Form>
      </PopoverContent>
    </Popover>
  );
}

// What is applied, visible without opening the popover; each one removable on its own.
export function ActiveLogFilters({ filters, onChange }) {
  const t = useTranslations("applications.logs.filters");
  const tc = useTranslations("common");
  const keys = activeFilterKeys(filters);
  if (!keys.length) return null;

  return (
    <div className="flex flex-wrap items-center gap-2 border-b px-4 py-2">
      {keys.map((key) => (
        <span
          key={key}
          className="inline-flex h-7 items-center gap-1 rounded-full border bg-muted/40 pr-1 pl-3 text-xs"
        >
          <span className="text-muted-foreground">{t(key)}:</span>
          <span className="max-w-48 truncate font-mono">{filters[key]}</span>
          <button
            type="button"
            onClick={() => onChange({ ...filters, [key]: "" })}
            aria-label={t("remove", { filter: t(key) })}
            className="flex size-5 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
          >
            <X className="size-3" />
          </button>
        </span>
      ))}
      <button
        type="button"
        onClick={() => onChange(EMPTY_LOG_FILTERS)}
        className="rounded text-xs font-medium text-foreground underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        {tc("clearFilters")}
      </button>
    </div>
  );
}
