"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { motion } from "motion/react";
import { ArrowLeft, Clock, ShieldAlert, Plus, X, CheckCircle2, Activity } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { CandidateDrawer } from "@/components/exams/CandidateDrawer";
import { monitorAssessment, extendSitting, type MonitorRow } from "@/lib/api";
import { demoMonitor } from "@/lib/demo";
import { cn } from "@/lib/cn";

function mmss(s: number | null) {
  if (s == null) return "—";
  const m = Math.floor(s / 60).toString().padStart(2, "0");
  return `${m}:${(s % 60).toString().padStart(2, "0")}`;
}
function riskTone(p: number) {
  return p >= 0.8 ? "rose" : p >= 0.6 ? "amber" : "sky";
}

export default function ExamMonitorPage() {
  const { id } = useParams<{ id: string }>();
  const [meta, setMeta] = useState(demoMonitor.assessment);
  const [rows, setRows] = useState<MonitorRow[]>(demoMonitor.sittings as MonitorRow[]);
  const [live, setLive] = useState(false);
  const [extendId, setExtendId] = useState<string | null>(null);
  const [detailId, setDetailId] = useState<string | null>(null);

  const load = useCallback(() => {
    monitorAssessment(id)
      .then((d) => { setMeta(d.assessment); setRows(d.sittings); setLive(true); })
      .catch(() => {});
  }, [id]);

  useEffect(() => { load(); const t = setInterval(load, 10000); return () => clearInterval(t); }, [load]);

  // Local 1s countdown between server refreshes.
  useEffect(() => {
    const t = setInterval(() => setRows((rs) => rs.map((r) =>
      r.status === "in_progress" && r.remaining_seconds != null ? { ...r, remaining_seconds: Math.max(0, r.remaining_seconds - 1) } : r,
    )), 1000);
    return () => clearInterval(t);
  }, []);

  const writing = rows.filter((r) => r.status === "in_progress").length;
  const submitted = rows.filter((r) => r.status === "submitted" || r.status === "graded").length;
  const flagged = rows.filter((r) => r.risk && r.risk.cheating_probability >= 0.6).length;

  async function applyExtend(minutes: number, reason: string) {
    if (extendId) { try { await extendSitting(extendId, minutes, reason); } catch { /* demo */ } }
    setExtendId(null);
    load();
  }

  return (
    <div className="space-y-6">
      <Link href="/exams" className="inline-flex items-center gap-2 text-sm text-faint hover:text-ink"><ArrowLeft className="h-4 w-4" /> Live examinations</Link>

      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-3 text-3xl font-semibold tracking-tight">
            {meta.title}
            <span className="inline-flex items-center gap-1.5 text-sm font-normal text-emerald-600 dark:text-emerald-300"><span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" /> live</span>
          </h1>
          <p className="mt-1 text-sm text-muted">{rows.length} candidates · {writing} writing · {submitted} submitted</p>
        </div>
        <div className="flex gap-3">
          <Stat icon={Activity} label="Writing" value={writing} tone="violet" />
          <Stat icon={CheckCircle2} label="Submitted" value={submitted} tone="emerald" />
          <Stat icon={ShieldAlert} label="Flagged" value={flagged} tone="amber" />
        </div>
      </div>

      <GlassCard glow className="overflow-hidden">
        <div className="hidden grid-cols-12 gap-4 border-b border-line px-6 py-3 text-xs uppercase tracking-wide text-faint sm:grid">
          <div className="col-span-3">Candidate</div>
          <div className="col-span-3">Progress</div>
          <div className="col-span-2">Time left</div>
          <div className="col-span-2">Proctoring</div>
          <div className="col-span-2 text-right">Actions</div>
        </div>
        <div className="divide-y divide-[var(--line)]">
          {rows.map((r) => {
            const pct = r.total ? Math.round((r.answered / r.total) * 100) : 0;
            const low = r.remaining_seconds != null && r.remaining_seconds < 300 && r.status === "in_progress";
            return (
              <div key={r.id} className="grid grid-cols-2 items-center gap-4 px-6 py-4 sm:grid-cols-12">
                <div className="col-span-3">
                  <button onClick={() => setDetailId(r.id)} className="font-medium hover:text-violet-600 dark:hover:text-violet-300 hover:underline">{r.candidate ?? "Candidate"}</button>
                  <span className="ml-2 align-middle"><Badge tone={r.status === "in_progress" ? "violet" : r.status === "submitted" || r.status === "graded" ? "emerald" : "neutral"} className="capitalize">{r.status.replace("_", " ")}</Badge></span>
                </div>
                <div className="col-span-3">
                  <div className="flex items-center justify-between text-xs text-faint"><span>{r.answered}/{r.total}</span><span>{pct}%</span></div>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-white/8"><div className="h-full rounded-full bg-gradient-to-r from-violet-500 to-sky-400" style={{ width: `${pct}%` }} /></div>
                </div>
                <div className={cn("col-span-2 flex items-center gap-1.5 font-mono text-sm", low && "text-rose-600 dark:text-rose-300")}>
                  <Clock className="h-3.5 w-3.5" /> {r.status === "in_progress" ? mmss(r.remaining_seconds) : "—"}
                </div>
                <div className="col-span-2">
                  {r.risk
                    ? <Link href={`/proctoring?focus=${r.risk.id}`}><Badge tone={riskTone(r.risk.cheating_probability)} className="cursor-pointer hover:brightness-110">{Math.round(r.risk.cheating_probability * 100)}% risk</Badge></Link>
                    : <span className="text-xs text-faint">clear</span>}
                </div>
                <div className="col-span-2 flex justify-end">
                  {(r.status === "in_progress" || r.status === "assigned") && (
                    <button onClick={() => setExtendId(r.id)} className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs text-violet-600 dark:text-violet-300 hover:bg-violet-500/10">
                      <Plus className="h-3.5 w-3.5" /> Add time
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </GlassCard>

      {!live && <p className="text-center text-xs text-faint">Demo monitor — sign in for live data.</p>}

      {extendId && <ExtendDialog onApply={applyExtend} onClose={() => setExtendId(null)} />}
      {detailId && <CandidateDrawer sittingId={detailId} onClose={() => setDetailId(null)} onAddTime={() => { setExtendId(detailId); setDetailId(null); }} />}
    </div>
  );
}

function Stat({ icon: Icon, label, value, tone }: { icon: React.ElementType; label: string; value: number; tone: "violet" | "emerald" | "amber" }) {
  const c = { violet: "text-violet-700 dark:text-violet-200", emerald: "text-emerald-700 dark:text-emerald-200", amber: "text-amber-700 dark:text-amber-200" }[tone];
  return (
    <div className="glass rounded-xl px-4 py-2 text-center">
      <div className={cn("flex items-center justify-center gap-1.5 text-lg font-semibold", c)}><Icon className="h-4 w-4" /> {value}</div>
      <div className="text-[10px] uppercase tracking-wide text-faint">{label}</div>
    </div>
  );
}

function ExtendDialog({ onApply, onClose }: { onApply: (minutes: number, reason: string) => void; onClose: () => void }) {
  const [minutes, setMinutes] = useState(10);
  const [reason, setReason] = useState("");
  return (
    <div className="fixed inset-0 z-50 grid place-items-center p-4">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
      <motion.div initial={{ opacity: 0, y: 16, scale: 0.98 }} animate={{ opacity: 1, y: 0, scale: 1 }} className="glass ring-gradient relative z-10 w-full max-w-sm rounded-2xl p-6">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold tracking-tight">Add time</h2>
          <button onClick={onClose} className="grid h-8 w-8 place-items-center rounded-lg text-faint hover:text-ink hover:bg-white/5"><X className="h-4 w-4" /></button>
        </div>
        <p className="mt-1 text-xs text-faint">Extends the candidate&apos;s server deadline — reopens it if it has already lapsed.</p>
        <div className="mt-4 flex gap-2">
          {[5, 10, 15, 30].map((m) => (
            <button key={m} onClick={() => setMinutes(m)} className={cn("flex-1 rounded-lg border py-2 text-sm transition", minutes === m ? "border-violet-400/50 bg-violet-500/10 text-ink" : "border-line glass text-muted hover:text-ink")}>{m}m</button>
          ))}
        </div>
        <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason (e.g. power outage)" className="mt-3 h-10 w-full rounded-lg border border-line bg-black/[0.02] dark:bg-white/[0.03] px-3 text-sm outline-none focus:border-violet-400/50" />
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="glass" onClick={onClose}>Cancel</Button>
          <Button onClick={() => onApply(minutes, reason)}>Grant {minutes} min</Button>
        </div>
      </motion.div>
    </div>
  );
}
