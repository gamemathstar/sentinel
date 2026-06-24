import { cn } from "@/lib/cn";

type Tone = "violet" | "emerald" | "amber" | "rose" | "sky" | "neutral";

const tones: Record<Tone, string> = {
  violet: "bg-violet-500/15 text-violet-700 dark:text-violet-200 border-violet-400/30",
  emerald: "bg-emerald-500/15 text-emerald-700 dark:text-emerald-200 border-emerald-400/30",
  amber: "bg-amber-500/15 text-amber-700 dark:text-amber-200 border-amber-400/30",
  rose: "bg-rose-500/15 text-rose-700 dark:text-rose-200 border-rose-400/30",
  sky: "bg-sky-500/15 text-sky-700 dark:text-sky-200 border-sky-400/30",
  neutral: "bg-black/5 dark:bg-white/8 text-zinc-600 dark:text-zinc-300 border-line",
};

export function Badge({
  tone = "neutral",
  className,
  ...props
}: React.HTMLAttributes<HTMLSpanElement> & { tone?: Tone }) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium",
        tones[tone],
        className,
      )}
      {...props}
    />
  );
}
