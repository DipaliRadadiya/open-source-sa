import { cookies } from "next/headers";
import { LIST_PER_PAGE_OPTIONS, PER_PAGE_COOKIE } from "@/lib/schemas/list";

/** The page size last picked on any list (Krishna, 5 Nov: one setting for all lists). */
export async function rememberedPerPage() {
  const value = Number((await cookies()).get(PER_PAGE_COOKIE)?.value);
  return LIST_PER_PAGE_OPTIONS.includes(value) ? value : LIST_PER_PAGE_OPTIONS[0];
}

/** `per_page` from the URL when valid, otherwise the remembered size. */
export async function perPageFrom(value) {
  return LIST_PER_PAGE_OPTIONS.includes(Number(value)) ? Number(value) : rememberedPerPage();
}
