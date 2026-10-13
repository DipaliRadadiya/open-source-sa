import { cn } from "@/lib/utils";
import { Input } from "@/components/ui/input";
import { CopyButton } from "@/components/ui/copy-button";

// Add/edit system user: masked, with a copy button and no eye (Krishna, 13 Oct). A
// password on screen can be read over someone's shoulder and used to log in to the server.
export function CopyablePasswordInput({ className, value, copyLabel, ...props }) {
  return (
    <div className="relative">
      <Input type="password" className={cn("pr-10", className)} value={value} {...props} />
      <span className="absolute inset-y-0 right-1 flex items-center">
        <CopyButton value={value} label={copyLabel} />
      </span>
    </div>
  );
}
