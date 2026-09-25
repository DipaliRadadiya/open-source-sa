import { listFiles } from "@/lib/api/files";

/**
 * Whether a 404 from move / copy / extract / compress is about the destination.
 *
 * The API answers a missing destination folder with its generic "That item
 * could not be found. It may have been deleted…", which reads as if the file
 * being moved had vanished. One listing of the folder tells the two apart; it
 * is only asked after a 404, so a normal submit costs nothing extra.
 *
 * Resolves to true only when the folder itself is confirmed missing.
 */
export async function destinationMissing(appId, error, folder) {
  if (error?.response?.status !== 404 || !folder) return false;
  try {
    await listFiles(appId, folder);
    return false;
  } catch (listError) {
    return listError?.response?.status === 404;
  }
}
