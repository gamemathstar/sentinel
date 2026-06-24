"use client";

import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { motion } from "motion/react";
import { ShieldAlert, ShieldCheck, ShieldX, Eye, Activity } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { listReviewQueue, reviewRisk, ApiError, type RiskRow } from "@/lib/api";
import { demoReviewQueue } from "@/lib/demo";
import { cn } from "@/lib/cn";

const SIGNAL_LABELS: Record<string, string> = {
  phone_detected: "Phone detected",
  face_absent: "Face absent",
  multiple_faces: "Multiple faces",
  tab_switch: "Tab switching",
  voice_detected: "Voice detected",
  vm_detected: "Virtual machine",
  remote_desktop: "Remote desktop",
  gaze_away: "Looking away",
};
const label = (t: string) => SIGNAL_LABELS[t] ?? t.replace(/_/g, " ");

function sev(p: number) {
  if (p >= 0.8) return { tone: "rose" as const, text: "text-rose-600 dark:text-rose-300", bar: "from-amber-400 to-rose-500" };
  if (p >= 0.6) return { tone: "amber" as const, text: "text-amber-600 dark:text-amber-300", bar: "from-yellow-400 to-amber-500" };
  return { tone: "sky" as const, text: "text-sky-600 dark:text-sky-300", bar: "from-sky-400 to-indigo-500" };
}

export default function ProctoringPage() {
  return (
    <Suspense fallback={null}>
      <ProctoringConsole />
    </Suspense>
  );
}

function ProctoringConsole() {
  const focus = useSearchParams().get("focus");
  const [queue, setQueue] = useState<RiskRow[]>(demoReviewQueue as RiskRow[]);
  const [live, setLive] = useState(false);
  const [selected, setSelected] = useState<string | null>(focus ?? demoReviewQueue[0]?.id ?? null);
  const [note, setNote] = useState<string | null>(null);

  useEffect(() => {
    listReviewQueue(0.5)
      .then((res) => {
        setQueue(res.data);
        setLive(true);
        // Honor a deep-linked focus if it's in the queue, else select the top.
        setSelected(focus && res.data.some((r) => r.id === focus) ? focus : (res.data[0]?.id ?? null));
      })
      .catch(() => {});
  }, [focus]);

  const current = queue.find((r) => r.id === selected) ?? null;

  async function decide(decision: "cleared" | "upheld") {
    if (!current) return;
    try {
      await reviewRisk(current.id, decision);
    } catch (e) {
      if (e instanceof ApiError && e.status !== 401) setNote(e.message);
    }
    const remaining = queue.filter((r) => r.id !== current.id);
    setQueue(remaining);
    setSelected(remaining[0]?.id ?? null);
    setNote(`Candidate ${decision === "upheld" ? "upheld for misconduct review" : "cleared"} — recorded${live ? "" : " (demo)"}.`);
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="flex items-center gap-2 text-3xl font-semibold tracking-tight">
          <ShieldAlert className="h-7 w-7 text-amber-500 dark:text-amber-300" /> Proctoring review
        </h1>
        <p className="mt-1 text-sm text-muted">
          Flagged sittings with an explainable risk score. A human decides — the system never auto-voids.
        </p>
      </div>

      {queue.length === 0 ? (
        <GlassCard className="grid place-items-center gap-2 p-16 text-center">
          <ShieldCheck className="h-10 w-10 text-emerald-500 dark:text-emerald-300" />
          <p className="text-sm text-muted">Review queue is clear. {note}</p>
        </GlassCard>
      ) : (
        <div className="grid gap-5 lg:grid-cols-[300px_1fr]">
          {/* Queue */}
          <div className="space-y-2">
            {queue.map((r) => {
              const s = sev(r.cheating_probability);
              return (
                <button
                  key={r.id}
                  onClick={() => setSelected(r.id)}
                  className={cn(
                    "w-full rounded-xl border px-4 py-3 text-left transition",
                    selected === r.id ? "border-violet-400/50 bg-violet-500/10" : "border-line glass hover:border-line-strong",
                  )}
                >
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium">{r.session?.sitting?.candidate?.full_name ?? "Candidate"}</span>
                    <span className={cn("text-sm font-semibold", s.text)}>{Math.round(r.cheating_probability * 100)}%</span>
                  </div>
                  <p className="mt-1 text-xs text-faint">{r.timeline[0] ? label(r.timeline[0].type) : "—"}</p>
                </button>
              );
            })}
            {!live && <p className="px-1 pt-1 text-xs text-faint">Demo queue — sign in for live sessions.</p>}
          </div>

          {/* Detail */}
          {current && <RiskPanel key={current.id} risk={current} onDecide={decide} note={note} />}
        </div>
      )}
    </div>
  );
}

function RiskPanel({ risk, onDecide, note }: { risk: RiskRow; onDecide: (d: "cleared" | "upheld") => void; note: string | null }) {
  const s = sev(risk.cheating_probability);
  const pct = Math.round(risk.cheating_probability * 100);

  return (
    <GlassCard glow className="space-y-6 p-6">
      <div className="flex items-start justify-between">
        <div>
          <p className="text-xs uppercase tracking-wide text-faint">Candidate</p>
          <h2 className="text-xl font-semibold tracking-tight">{risk.session?.sitting?.candidate?.full_name ?? "Candidate"}</h2>
        </div>
        <Badge tone={s.tone} className="capitalize">{risk.status}</Badge>
      </div>

      {/* Probability meter */}
      <div>
        <div className="flex items-end justify-between">
          <span className="text-sm text-muted">Cheating probability</span>
          <span className={cn("text-2xl font-semibold", s.text)}>{pct}%</span>
        </div>
        <div className="mt-2 h-3 overflow-hidden rounded-full bg-white/8">
          <motion.div
            initial={{ width: 0 }}
            animate={{ width: `${pct}%` }}
            transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
            className={cn("h-full rounded-full bg-gradient-to-r", s.bar)}
          />
        </div>
        <p className="mt-2 text-xs text-faint">Suspicion score {risk.suspicion_score} · calibrated, noisy-OR aggregate</p>
      </div>

      {/* Explainable timeline */}
      <div>
        <p className="flex items-center gap-2 text-sm font-medium"><Activity className="h-4 w-4 text-violet-500 dark:text-violet-300" /> Why — contributing signals</p>
        <div className="mt-3 space-y-2.5">
          {risk.timeline.map((t) => (
            <div key={t.type} className="glass rounded-xl p-3">
              <div className="flex items-center justify-between text-sm">
                <span className="font-medium">{label(t.type)}</span>
                <span className="text-faint">contributes {Math.round(t.contribution * 100)}%</span>
              </div>
              <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/8">
                <div className="h-full rounded-full bg-gradient-to-r from-violet-500 to-sky-400" style={{ width: `${t.contribution * 100}%` }} />
              </div>
              <p className="mt-1.5 text-xs text-faint">
                weight {t.weight} · confidence {Math.round(t.combined_confidence * 100)}% · {t.occurrences}×
              </p>
            </div>
          ))}
        </div>
      </div>

      <div className="flex items-center gap-3">
        <Button variant="glass" onClick={() => onDecide("cleared")}>
          <ShieldCheck className="h-4 w-4 text-emerald-500 dark:text-emerald-300" /> Clear candidate
        </Button>
        <Button onClick={() => onDecide("upheld")} className="!bg-gradient-to-r !from-rose-500 !to-amber-500">
          <ShieldX className="h-4 w-4" /> Uphold for review
        </Button>
        <span className="ml-auto inline-flex items-center gap-1.5 text-xs text-faint"><Eye className="h-3.5 w-3.5" /> evidence on request</span>
      </div>
      {note && <p className="rounded-lg bg-white/5 px-3 py-2 text-xs text-muted">{note}</p>}
    </GlassCard>
  );
}
