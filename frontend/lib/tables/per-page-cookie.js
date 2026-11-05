import { LIST_PER_PAGE_OPTIONS, PER_PAGE_COOKIE } from "@/lib/schemas/list";

// Browser side of the remembered page size; the server reads it in remembered-per-page.js.
export function savePerPage(value) {
  if (!LIST_PER_PAGE_OPTIONS.includes(Number(value))) return;
  document.cookie = `${PER_PAGE_COOKIE}=${Number(value)}; path=/; max-age=31536000; samesite=lax`;
}

export function readPerPage() {
  const match = document.cookie.match(new RegExp(`(?:^|; )${PER_PAGE_COOKIE}=(\\d+)`));
  const value = Number(match?.[1]);
  return LIST_PER_PAGE_OPTIONS.includes(value) ? value : LIST_PER_PAGE_OPTIONS[0];
}
