"use client";

import { useTransition } from "react";
import { useLocale } from "next-intl";
import { useRouter } from "next/navigation";
import { Languages, Check, Loader2, ChevronDown } from "lucide-react";
import { locales, localeNames } from "@/i18n/routing";
import { setLocale } from "@/lib/i18n/locale-actions";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

export function LocaleSwitcher({ align = "end" }) {
  const active = useLocale();
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  function choose(locale) {
    if (locale === active) return;
    startTransition(async () => {
      await setLocale(locale);
      router.refresh();
    });
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className="gap-1.5"
          aria-label={localeNames[active] ?? "Language"}
        >
          {pending ? (
            <Loader2 className="size-4 shrink-0 animate-spin" />
          ) : (
            <Languages className="size-4 shrink-0" />
          )}
          <span className="hidden max-w-24 truncate sm:inline">
            {localeNames[active] ?? active}
          </span>
          <span className="font-medium uppercase sm:hidden">{active}</span>
          <ChevronDown className="size-3.5 shrink-0 opacity-60" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align={align} className="w-44">
        {locales.map((locale) => (
          <DropdownMenuItem
            key={locale}
            onSelect={() => choose(locale)}
            className="justify-between"
          >
            {localeNames[locale] ?? locale}
            {locale === active ? <Check className="size-4" /> : null}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
