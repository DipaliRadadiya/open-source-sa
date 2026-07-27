import { cache } from "react";
import { brandingSchema } from "@/lib/schemas/branding";

const DEFAULT_BRANDING = {
  name: "ServerAvatar",
  logo: "https://app.serveravatar.com/logo/SaLogoDark.png",
  logo_dark: "https://app.serveravatar.com/logo/dark-logo.png",
  icon: "https://app.serveravatar.com/logo/logo-sm.png",
  icon_dark: "https://app.serveravatar.com/logo/dark-logo-sm.png",
  favicon: "https://app.serveravatar.com/logo/logo-sm.png",
  primary_color: "#076aff",
};

export const getBranding = cache(async () => {
  try {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/branding`, {
      next: { revalidate: 3600, tags: ["branding"] },
    });

    if (!res.ok) return DEFAULT_BRANDING;

    const data = await res.json();
    const parsed = brandingSchema.safeParse(data?.branding);

    return parsed.success ? parsed.data : DEFAULT_BRANDING;
  } catch {
    return DEFAULT_BRANDING;
  }
});
