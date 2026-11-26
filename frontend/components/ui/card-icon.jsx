import { HeadIcon } from "@/components/ui/head-icon";

export function CardIcon({ icon, tone = "primary", className }) {
  return <HeadIcon icon={icon} tone={tone === "destructive" ? "destructive" : "default"} className={className} />;
}
