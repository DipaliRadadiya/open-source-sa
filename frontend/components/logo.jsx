"use client";

import { useBranding } from "@/hooks/use-branding";

export function Logo({ collapsed = false, className }) {
  const branding = useBranding();

  const light = collapsed ? branding.icon || branding.logo : branding.logo;
  const dark = collapsed
    ? branding.icon_dark || branding.icon || branding.logo_dark || branding.logo
    : branding.logo_dark || branding.logo;

  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src={light} alt={branding.name} className={`dark:hidden ${className ?? ""}`} />
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src={dark} alt={branding.name} className={`hidden dark:block ${className ?? ""}`} />
    </>
  );
}
