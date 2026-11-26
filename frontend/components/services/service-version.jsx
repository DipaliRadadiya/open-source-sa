import { useTranslations } from "next-intl";
import { HoverHint } from "@/components/ui/hover-hint";

export function ServiceVersion({ version, packageVersion }) {
  const t = useTranslations("services");
  if (!version) return null;
  return (
    <>
      {" "}
      <HoverHint content={packageVersion ? t("packageVersion", { version: packageVersion }) : undefined}><span
        className="whitespace-nowrap tabular-nums"
      >
        · {version}
      </span></HoverHint>
    </>
  );
}
