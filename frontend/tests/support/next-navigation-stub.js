// Stands in for next/navigation outside an App Router tree.
const router = { push() {}, replace() {}, refresh() {}, prefetch() {}, back() {}, forward() {} };

export const useRouter = () => router;
export const usePathname = () => "/";
export const useSearchParams = () => new URLSearchParams();
