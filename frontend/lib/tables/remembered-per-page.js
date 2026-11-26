import { cookies } from "next/headers";
import { LIST_PER_PAGE_OPTIONS, PER_PAGE_COOKIE } from "@/lib/schemas/list";

export async function rememberedPerPage() {
  const value = Number((await cookies()).get(PER_PAGE_COOKIE)?.value);
  return LIST_PER_PAGE_OPTIONS.includes(value) ? value : LIST_PER_PAGE_OPTIONS[0];
}

export async function perPageFrom(value) {
  return LIST_PER_PAGE_OPTIONS.includes(Number(value)) ? Number(value) : rememberedPerPage();
}
