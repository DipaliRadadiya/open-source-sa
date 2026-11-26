import { read } from "@/lib/api/read";
import { trashResponseSchema } from "@/lib/schemas/file";

// A failed read must not look like an empty trash.
export async function getTrash(appId) {
  const { data, failed, status, failure, message, debug } = await read(
    `/applications/${appId}/files/trash`,
    trashResponseSchema,
  );

  return {
    trash: data?.trash ?? [],
    totalSize: data?.total_size ?? null,
    retentionDays: data?.retention_days ?? null,
    failed,
    status,
    failure,
    message,
    debug,
  };
}
