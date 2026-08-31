import { cache } from "react";
import { brandingSchema } from "@/lib/schemas/branding";
import { DEFAULT_BRANDING, mergeBranding } from "@/lib/branding/merge-branding";

export const getBranding = cache(async () => {
  try {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/branding`, {
      next: { revalidate: 3600, tags: ["branding"] },
    });

    if (!res.ok) return { ...DEFAULT_BRANDING };

    const data = await res.json();
    const parsed = brandingSchema.safeParse(data?.branding);

    return mergeBranding(parsed.success ? parsed.data : data?.branding);
  } catch {
    return { ...DEFAULT_BRANDING };
  }
});
