import { read } from "@/lib/api/read";
import { cronjobsResponseSchema } from "@/lib/schemas/cronjob";
import { perPageFrom } from "@/lib/tables/remembered-per-page";

const ACTIVE_VALUES = ["true", "false"];
const EMPTY = {
  cronjobs: [],
  meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
};

const failedWith = (result) => ({
  ...EMPTY,
  failed: true,
  status: result.status,
  failure: result.failure,
  message: result.message,
  debug: result.debug,
});

export async function getCronjobs(searchParams = {}) {
  const perPage = await perPageFrom(searchParams.per_page);
  const page = Math.max(1, Number(searchParams.page) || 1);

  const user = searchParams.user?.trim();
  const isSystemUser = user && /^\d+$/.test(user);

  const active = ACTIVE_VALUES.includes(searchParams.active)
    ? searchParams.active
    : undefined;

  const result = await read("/cronjobs", cronjobsResponseSchema, {
    searchParams: {
      "filter[system_user_id]": isSystemUser ? user : undefined,
      "filter[username]": user && !isSystemUser ? user : undefined,
      "filter[active]": active,
      per_page: perPage,
      page,
    },
  });

  if (result.failed) return failedWith(result);

  return { ...result.data, failed: false, status: result.status, failure: null, message: null, debug: false };
}
