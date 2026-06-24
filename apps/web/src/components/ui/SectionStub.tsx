import type { LucideIcon } from "lucide-react";
import { GlassCard } from "./GlassCard";
import { Badge } from "./Badge";

/** A polished placeholder for sections whose full UI is a later phase. */
export function SectionStub({
  icon: Icon,
  title,
  blurb,
  points,
}: {
  icon: LucideIcon;
  title: string;
  blurb: string;
  points: string[];
}) {
  return (
    <div className="space-y-7">
      <div className="flex items-center gap-4">
        <div className="grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-violet-500/30 to-transparent text-violet-700 dark:text-violet-200">
          <Icon className="h-6 w-6" strokeWidth={1.8} />
        </div>
        <div>
          <h1 className="text-3xl font-semibold tracking-tight">{title}</h1>
          <p className="mt-1 text-sm text-muted">{blurb}</p>
        </div>
        <Badge tone="violet" className="ml-auto">API ready</Badge>
      </div>

      <GlassCard glow className="p-8">
        <p className="text-sm text-muted">
          The backend for this module is built, tested, and live on the API. This screen is
          on the frontend roadmap — the endpoints below already power it.
        </p>
        <div className="mt-6 grid gap-3 sm:grid-cols-2">
          {points.map((p) => (
            <div key={p} className="glass glass-hover rounded-xl px-4 py-3 text-sm">
              <span className="font-mono text-xs text-violet-700 dark:text-violet-300">{p}</span>
            </div>
          ))}
        </div>
      </GlassCard>
    </div>
  );
}
