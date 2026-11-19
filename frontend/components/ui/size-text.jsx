"use client";

import { useFormatter } from "next-intl";
import { localizeSizeText } from "@/lib/format/bytes";

/** A size the API sent as text ("1.6 GB"), in the reader's number format; `fallback` when empty. */
export function SizeText({ value, fallback = "—" }) {
  const format = useFormatter();
  if (value === null || value === undefined || value === "") return fallback;
  return localizeSizeText(value, format);
}
