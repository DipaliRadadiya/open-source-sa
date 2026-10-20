import { useTranslations } from "next-intl";
import { ChevronDown, KeyRound } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormMessage,
} from "@/components/ui/form";

const NO_REGISTRY = "__none__";

// A saved registry login, folded away: a public image (the normal case) needs none.
// Shared by the image and compose modes so both offer it the same way.
export function DockerRegistryPicker({ form, registryOptions = [], open, onOpenChange }) {
  const t = useTranslations("applications.dockerImage");
  if (!registryOptions.length) return null;

  return (
    <Collapsible open={open} onOpenChange={onOpenChange}>
      <CollapsibleTrigger asChild>
        <Button type="button" variant="ghost" size="sm" className="-ms-2 gap-1.5 text-muted-foreground">
          <KeyRound className="size-3.5" aria-hidden />
          {t("privateRegistry")}
          <ChevronDown className={cn("size-3.5 transition-transform", open && "rotate-180")} aria-hidden />
        </Button>
      </CollapsibleTrigger>
      <CollapsibleContent className="pt-2">
        <FormField
          control={form.control}
          name="registry_id"
          render={({ field }) => (
            <FormItem data-field-name="registry_id" className="max-w-md">
              <Select
                value={field.value ? String(field.value) : NO_REGISTRY}
                onValueChange={(value) => field.onChange(value === NO_REGISTRY ? "" : value)}
              >
                <FormControl>
                  <SelectTrigger className="w-full" aria-label={t("privateRegistry")}>
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectItem value={NO_REGISTRY}>{t("publicImage")}</SelectItem>
                  {registryOptions.map((option) => (
                    <SelectItem key={option.value} value={String(option.value)}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <FormDescription>{t("privateRegistryHint")}</FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
      </CollapsibleContent>
    </Collapsible>
  );
}
