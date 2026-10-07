import { useEffect, useState } from "react";
import { inspectDockerImage } from "@/lib/api/docker";
import { imageInspectResponseSchema } from "@/lib/schemas/docker";

/**
 * What the registry says about an image, or null while unknown. A failed
 * request stays null: "could not ask" must not read as "declares nothing".
 */
export function useImageInspection(image, registryId) {
  const [result, setResult] = useState(null);
  useEffect(() => {
    if (!image) return undefined;
    const controller = new AbortController();
    inspectDockerImage(image, { registryId, signal: controller.signal })
      .then(({ data }) => {
        const parsed = imageInspectResponseSchema.safeParse(data);
        setResult(parsed.success ? parsed.data : null);
      })
      .catch(() => {});
    return () => controller.abort();
  }, [image, registryId]);
  return result;
}
