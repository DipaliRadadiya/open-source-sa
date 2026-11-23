import { HeadIcon } from "@/components/ui/head-icon";

// Kept as a name for the database screens; the chip itself is the shared heading icon.
export function CardIcon({ icon, tone = "primary", className }) {
  return <HeadIcon icon={icon} tone={tone === "destructive" ? "destructive" : "default"} className={className} />;
}
