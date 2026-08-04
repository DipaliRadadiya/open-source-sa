"use client";

import { useState } from "react";
import { Eye, EyeOff } from "lucide-react";
import { useTranslations } from "next-intl";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

/**
 * Password field with a show/hide toggle. Accepts all Input props (spread
 * react-hook-form's field onto it). The toggle is outside the tab order so
 * keyboard flow moves field → next field; it's operable by mouse/touch.
 */
export function PasswordInput({ className, show: showProp, onShowChange, ...props }) {
  const t = useTranslations("common");
  const [showState, setShowState] = useState(false);
  // Controlled when a parent passes `show` (e.g. reveal right after Generate),
  // otherwise self-managed.
  const show = showProp ?? showState;
  const setShow = (next) => {
    const value = typeof next === "function" ? next(show) : next;
    if (onShowChange) onShowChange(value);
    else setShowState(value);
  };

  return (
    <div className="relative">
      <Input
        type={show ? "text" : "password"}
        className={cn("pr-10", className)}
        {...props}
      />
      <button
        type="button"
        tabIndex={-1}
        onClick={() => setShow((s) => !s)}
        aria-label={show ? t("hidePassword") : t("showPassword")}
        className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-lg text-muted-foreground transition-colors hover:text-foreground focus-visible:text-foreground focus-visible:outline-none"
      >
        {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
      </button>
    </div>
  );
}
