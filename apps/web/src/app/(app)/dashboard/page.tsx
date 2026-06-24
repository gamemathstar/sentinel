"use client";

import { Users, FileCheck2, TrendingUp, ShieldAlert, ArrowUpRight } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { StatCard } from "@/components/ui/StatCard";
import { Badge } from "@/components/ui/Badge";
import { ButtonLink } from "@/components/ui/Button";
import { demoStats, demoDistribution, demoRisk, demoActivity } from "@/lib/demo";

export default function DashboardPage() {
  const max = Math.max(...demoDistribution);

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-sm text-muted">Welcome back</p>
          <h1 className="mt-1 text-3xl font-semibold tracking-tight">
            Assessment <span className="gradient-text">command center</span>
          </h1>
        </div>
        <ButtonLink href="/assessments/new" className="shrink-0">
          New assessment <ArrowUpRight className="h-4 w-4" />
        </ButtonLink>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard icon={Users} label="Active candidates" value={demoStats.candidates} accent="violet" hint="+12% wk" delay={0} />
        <StatCard icon={FileCheck2} label="Assessments" value={demoStats.assessments} accent="sky" hint="4 live" delay={0.06} />
        <StatCard icon={TrendingUp} label="Avg. pass rate" value={demoStats.passRate} accent="emerald" hint="+3 pts" delay={0.12} />
        <StatCard icon={ShieldAlert} label="Flagged sittings" value={demoStats.flagged} accent="amber" hint="needs review" delay={0.18} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        {/* Score distribution */}
        <GlassCard glow className="p-6 lg:col-span-2">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-semibold tracking-tight">Score distribution</h2>
              <p className="text-sm text-muted">CSC101 Final — 312 candidates</p>
            </div>
            <Badge tone="emerald">KR-20 0.84</Badge>
          </div>
          <div className="mt-8 flex h-44 items-end gap-2">
            {demoDistribution.map((v, i) => (
              <div key={i} className="group flex-1">
                <div
                  className="w-full rounded-t-md bg-gradient-to-t from-violet-600/40 via-indigo-500/70 to-sky-400 transition-all duration-300 group-hover:from-violet-500 group-hover:to-cyan-300"
                  style={{ height: `${(v / max) * 100}%` }}
                />
              </div>
            ))}
          </div>
          <div className="mt-3 flex justify-between text-xs text-faint">
            <span>0%</span><span>25%</span><span>50%</span><span>75%</span><span>100%</span>
          </div>
        </GlassCard>

        {/* Proctoring risk */}
        <GlassCard className="p-6">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold tracking-tight">Proctoring review</h2>
            <Badge tone="amber">{demoRisk.length}</Badge>
          </div>
          <div className="mt-5 space-y-3">
            {demoRisk.map((r) => (
              <div key={r.candidate} className="glass glass-hover rounded-xl p-3.5">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium">{r.candidate}</span>
                  <span className="text-sm font-semibold text-amber-600 dark:text-amber-300">
                    {Math.round(r.probability * 100)}%
                  </span>
                </div>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/8">
                  <div
                    className="h-full rounded-full bg-gradient-to-r from-amber-400 to-rose-500"
                    style={{ width: `${r.probability * 100}%` }}
                  />
                </div>
                <p className="mt-2 text-xs text-faint">
                  top signal · <span className="font-mono">{r.signal}</span>
                </p>
              </div>
            ))}
          </div>
        </GlassCard>
      </div>

      {/* Recent activity */}
      <GlassCard className="p-6">
        <h2 className="text-lg font-semibold tracking-tight">Recent activity</h2>
        <div className="mt-4 divide-y divide-white/5">
          {demoActivity.map((a, i) => (
            <div key={i} className="flex items-center gap-3 py-3">
              <span className="h-2 w-2 rounded-full bg-gradient-to-br from-violet-400 to-sky-400" />
              <span className="text-sm">
                <span className="font-medium">{a.who}</span>{" "}
                <span className="text-muted">{a.what}</span>
              </span>
              <span className="ml-auto text-xs text-faint">{a.when}</span>
            </div>
          ))}
        </div>
      </GlassCard>
    </div>
  );
}
