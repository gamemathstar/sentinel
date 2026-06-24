"use client";

import { motion } from "motion/react";
import type { LucideIcon } from "lucide-react";
import { GlassCard } from "./GlassCard";
import { cn } from "@/lib/cn";

type Accent = "violet" | "sky" | "emerald" | "amber";

const accents: Record<Accent, string> = {
  violet: "from-violet-500/30 text-violet-700 dark:text-violet-200",
  sky: "from-sky-500/30 text-sky-700 dark:text-sky-200",
  emerald: "from-emerald-500/30 text-emerald-700 dark:text-emerald-200",
  amber: "from-amber-500/30 text-amber-700 dark:text-amber-200",
};

export function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  accent = "violet",
  delay = 0,
}: {
  icon: LucideIcon;
  label: string;
  value: string;
  hint?: string;
  accent?: Accent;
  delay?: number;
}) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 14 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, delay, ease: [0.22, 1, 0.36, 1] }}
    >
      <GlassCard hover className="p-5">
        <div className="flex items-start justify-between">
          <div
            className={cn(
              "grid h-11 w-11 place-items-center rounded-xl bg-gradient-to-br to-transparent",
              accents[accent],
            )}
          >
            <Icon className="h-5 w-5" strokeWidth={1.8} />
          </div>
          {hint && <span className="text-xs text-faint">{hint}</span>}
        </div>
        <div className="mt-4 text-3xl font-semibold tracking-tight">{value}</div>
        <div className="mt-1 text-sm text-muted">{label}</div>
      </GlassCard>
    </motion.div>
  );
}
