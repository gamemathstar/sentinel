"use client";

import { useCallback, useEffect, useState } from "react";
import { motion } from "motion/react";
import { BarChart3, RefreshCw, ChevronDown, Gauge, Sigma, Ruler, AlertTriangle, CheckCircle2 } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import {
  listAssessments, getReliability, getAssessmentItemStats, compileAnalytics,
  ApiError, type AssessmentRow, type Reliability, type ItemStat, type DistractorOption,
} from "@/lib/api";
import { demoAssessments, demoReliability, demoItemStats } from "@/lib/demo";
import { cn } from "@/lib/cn";

/* ── Psychometric interpretation (classical test theory) ── */

function relGrade(v: number) {
  if (v >= 0.9) return { label: "Excellent", tone: "emerald" as const };
  if (v >= 0.8) return { label: "Good", tone: "emerald" as const };
  if (v >= 0.7) return { label: "Acceptable", tone: "sky" as const };
  if (v >= 0.6) return { label: "Questionable", tone: "amber" as const };
  return { label: "Poor", tone: "rose" as const };
}
function facilityGrade(p: number) {
  if (p > 0.9) return { label: "Too easy", tone: "amber" as const, text: "text-amber-600 dark:text-amber-300" };
  if (p < 0.3) return { label: "Too hard", tone: "amber" as const, text: "text-amber-600 dark:text-amber-300" };
  return { label: "Well-targeted", tone: "emerald" as const, text: "text-emerald-600 dark:text-emerald-300" };
}
function discGrade(d: number) {
  if (d < 0) return { label: "Negative — review key", tone: "rose" as const, text: "text-rose-600 dark:text-rose-300" };
  if (d < 0.2) return { label: "Weak", tone: "amber" as const, text: "text-amber-600 dark:text-amber-300" };
  if (d < 0.3) return { label: "Fair", tone: "sky" as const, text: "text-sky-600 dark:text-sky-300" };
  return { label: "Strong", tone: "emerald" as const, text: "text-emerald-600 dark:text-emerald-300" };
}
const pct = (v: number) => `${Math.round(v * 100)}%`;

export default function AnalyticsPage() {
  const [assessments, setAssessments] = useState<AssessmentRow[]>(
    demoAssessments.map((a) => ({ id: a.id, title: a.title, kind: a.kind, status: a.status })),
  );
  const [selected, setSelected] = useState<string>(demoAssessments[0].id);
  const [rel, setRel] = useState<Reliability | null>(demoReliability);
  const [items, setItems] = useState<ItemStat[]>(demoItemStats as ItemStat[]);
  const [live, setLive] = useState(false);
  const [compiling, setCompiling] = useState(false);
  const [note, setNote] = useState<string | null>(null);
  const [open, setOpen] = useState<string | null>(null);

  // Assessment list (for the picker).
  useEffect(() => {
    listAssessments().then((r) => { if (r.data.length) { setAssessments(r.data); setSelected((s) => r.data.some((a) => a.id === s) ? s : r.data[0].id); } }).catch(() => {});
  }, []);

  const load = useCallback((id: string) => {
    setNote(null);
    Promise.all([
      getReliability(id).then((r) => setRel(r)).catch(() => setRel(null)),
      getAssessmentItemStats(id).then((r) => { setItems(r.data); setLive(true); }).catch(() => {}),
    ]);
  }, []);

  useEffect(() => { load(selected); }, [selected, load]);

  async function recompile() {
    setCompiling(true);
    setNote(null);
    try {
      const r = await compileAnalytics(selected);
      setRel(r);
      const stats = await getAssessmentItemStats(selected);
      setItems(stats.data);
      setNote("Recomputed from graded sittings.");
    } catch (e) {
      setNote(e instanceof ApiError ? e.message : "No graded sittings to analyse yet.");
    } finally {
      setCompiling(false);
    }
  }

  const flagged = items.filter((i) => i.discrimination_index != null && i.discrimination_index < 0.2).length;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2.5 text-3xl font-semibold tracking-tight"><BarChart3 className="h-7 w-7 text-violet-500" /> Analytics & Psychometrics</h1>
          <p className="mt-1 text-sm text-muted">Reliability and per-item analysis from finalized scoring — computed off the exam hot path.</p>
        </div>
        <div className="flex items-center gap-3">
          <div className="relative">
            <select
              value={selected} onChange={(e) => setSelected(e.target.value)}
              className="h-10 appearance-none rounded-xl border border-line glass pl-4 pr-10 text-sm outline-none focus:border-violet-400/50"
            >
              {assessments.map((a) => <option key={a.id} value={a.id}>{a.title}</option>)}
            </select>
            <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-faint" />
          </div>
          <Button onClick={recompile} disabled={compiling}>
            <RefreshCw className={cn("h-4 w-4", compiling && "animate-spin")} /> {compiling ? "Compiling…" : "Recompile"}
          </Button>
        </div>
      </div>

      {note && <p className="text-xs text-amber-600 dark:text-amber-300">{note}</p>}

      {/* Reliability headline */}
      <div className="grid gap-4 sm:grid-cols-3">
        <ReliabilityCard icon={Gauge} label="KR-20" value={rel?.kr20 ?? null} hint="Internal consistency for dichotomous items." grade />
        <ReliabilityCard icon={Sigma} label="Cronbach's α" value={rel?.cronbach_alpha ?? null} hint="Internal consistency across all item formats." grade />
        <ReliabilityCard icon={Ruler} label="Std. error (SEM)" value={rel?.sem ?? null} hint="Expected spread of a true score, in raw points." unit="pts" />
      </div>

      {/* Item analysis */}
      <GlassCard glow className="overflow-hidden">
        <div className="flex items-center justify-between border-b border-line px-6 py-4">
          <div>
            <h2 className="text-lg font-semibold tracking-tight">Item analysis</h2>
            <p className="text-xs text-faint">Facility (p), discrimination (point-biserial) and distractor health per item.</p>
          </div>
          {flagged > 0 && <Badge tone="amber"><AlertTriangle className="mr-1 h-3 w-3" /> {flagged} need review</Badge>}
        </div>

        <div className="hidden grid-cols-12 gap-4 border-b border-line px-6 py-3 text-xs uppercase tracking-wide text-faint sm:grid">
          <div className="col-span-5">Item</div>
          <div className="col-span-2">Facility</div>
          <div className="col-span-2">Discrimination</div>
          <div className="col-span-2">n</div>
          <div className="col-span-1" />
        </div>

        <div className="divide-y divide-[var(--line)]">
          {items.map((it, idx) => {
            const hasData = it.facility_index != null && it.discrimination_index != null;
            const fg = it.facility_index != null ? facilityGrade(it.facility_index) : null;
            const dg = it.discrimination_index != null ? discGrade(it.discrimination_index) : null;
            const isOpen = open === it.item_id;
            const distractors = Array.isArray(it.distractor_analysis) ? (it.distractor_analysis as DistractorOption[]) : null;
            return (
              <div key={it.item_id}>
                <button
                  onClick={() => setOpen(isOpen ? null : it.item_id)}
                  className="grid w-full grid-cols-2 items-center gap-4 px-6 py-4 text-left transition hover:bg-white/[0.02] sm:grid-cols-12"
                >
                  <div className="col-span-5 min-w-0">
                    <div className="flex items-center gap-2 text-xs text-faint">
                      <span className="tabular-nums">Q{idx + 1}</span>
                      <Badge tone="neutral" className="capitalize">{it.type}</Badge>
                      {it.bloom_level && <span className="capitalize text-faint">{it.bloom_level}</span>}
                    </div>
                    <p className="mt-1 truncate text-sm">{it.stem || <span className="text-faint">— untitled —</span>}</p>
                  </div>
                  {hasData ? (
                    <>
                      <div className="col-span-2">
                        <div className={cn("text-sm font-medium tabular-nums", fg!.text)}>{pct(it.facility_index!)}</div>
                        <div className="text-[10px] text-faint">{fg!.label}</div>
                      </div>
                      <div className="col-span-2">
                        <div className={cn("text-sm font-medium tabular-nums", dg!.text)}>{it.discrimination_index!.toFixed(2)}</div>
                        <div className="text-[10px] text-faint">{dg!.label}</div>
                      </div>
                      <div className="col-span-2 text-sm tabular-nums text-muted">{it.sample_n ?? "—"}</div>
                    </>
                  ) : (
                    <div className="col-span-6 text-xs text-faint">Awaiting graded data — recompile after sittings are marked.</div>
                  )}
                  <div className="col-span-1 flex justify-end">
                    {distractors && <ChevronDown className={cn("h-4 w-4 text-faint transition", isOpen && "rotate-180")} />}
                  </div>
                </button>

                {isOpen && distractors && (
                  <motion.div initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: "auto" }} className="overflow-hidden bg-black/[0.02] px-6 py-4 dark:bg-white/[0.02]">
                    <p className="mb-3 text-xs font-medium uppercase tracking-wide text-faint">Distractor analysis</p>
                    <div className="space-y-2">
                      {distractors.map((o) => (
                        <div key={o.key} className="flex items-center gap-3">
                          <span className={cn("grid h-6 w-6 place-items-center rounded-md text-xs font-medium", o.is_key ? "bg-emerald-500/15 text-emerald-600 dark:text-emerald-300" : "glass text-muted")}>{o.key}</span>
                          <div className="h-2 flex-1 overflow-hidden rounded-full bg-white/8">
                            <div className={cn("h-full rounded-full", o.is_key ? "bg-gradient-to-r from-emerald-500 to-teal-400" : "bg-gradient-to-r from-slate-400 to-slate-500")} style={{ width: `${Math.max(2, o.share * 100)}%` }} />
                          </div>
                          <span className="w-14 text-right text-xs tabular-nums text-faint">{pct(o.share)}</span>
                          {o.is_key && <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />}
                        </div>
                      ))}
                    </div>
                    {distractors.some((o) => !o.is_key && o.share > 0.35) && (
                      <p className="mt-3 flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-300"><AlertTriangle className="h-3.5 w-3.5" /> A distractor is drawing more than the key — likely a miskey or ambiguous wording.</p>
                    )}
                  </motion.div>
                )}
              </div>
            );
          })}
        </div>
      </GlassCard>

      {!live && <p className="text-center text-xs text-faint">Demo analytics — sign in for live psychometrics.</p>}
    </div>
  );
}

function ReliabilityCard({ icon: Icon, label, value, hint, grade, unit }: {
  icon: React.ElementType; label: string; value: number | null; hint: string; grade?: boolean; unit?: string;
}) {
  const g = grade && value != null ? relGrade(value) : null;
  return (
    <GlassCard className="p-5">
      <div className="flex items-center justify-between">
        <span className="flex items-center gap-2 text-sm text-muted"><Icon className="h-4 w-4 text-violet-500" /> {label}</span>
        {g && <Badge tone={g.tone}>{g.label}</Badge>}
      </div>
      <div className="mt-3 text-3xl font-semibold tabular-nums">
        {value == null ? <span className="text-faint">—</span> : <>{value.toFixed(2)}{unit && <span className="ml-1 text-base text-faint">{unit}</span>}</>}
      </div>
      <p className="mt-1.5 text-xs text-faint">{hint}</p>
    </GlassCard>
  );
}
