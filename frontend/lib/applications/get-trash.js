import { read } from "@/lib/api/read";
import { trashResponseSchema } from "@/lib/schemas/file";

// A failed read must never render as an empty trash; `read()` keeps the failure kind.
export async function getTrash(appId) {
  const { data, failed, status, failure, message, debug } = await read(
    `/applications/${appId}/files/trash`,
    trashResponseSchema,
  );

  return {
    trash: data?.trash ?? [],
    totalSize: data?.total_size_human ?? null,
    retentionDays: data?.retention_days ?? null,
    failed,
    status,
    failure,
    message,
    debug,
  };
}
