"use client";

import { useFormatter } from "next-intl";
import { localizeSizeText } from "@/lib/format/bytes";

export function SizeText({ value, fallback = "—" }) {
  const format = useFormatter();
  if (value === null || value === undefined || value === "") return fallback;
  return localizeSizeText(value, format);
}
