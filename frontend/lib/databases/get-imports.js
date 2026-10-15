import { read } from "@/lib/api/read";
import { importsResponseSchema } from "@/lib/schemas/database";

// The database page's first render; the card polls the same endpoint while one runs.
export async function getImports(databaseId) {
  const result = await read(`/databases/${databaseId}/imports`, importsResponseSchema);
  return { imports: result.failed ? [] : (result.data?.imports ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message };
}
