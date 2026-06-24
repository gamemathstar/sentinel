"use client";

import { useEffect, useState } from "react";
import { motion, AnimatePresence } from "motion/react";
import { PenLine, Sparkles, Check, Scale, User, Bot, FileText } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import {
  listGradingTasks, getGradingTask, aiSuggestGrade, submitGradeMark, reconcileGrade,
  ApiError, type GradingTaskRow, type GradingTaskDetail,
} from "@/lib/api";
import { demoGradingTasks, demoGradingDetails } from "@/lib/demo";
import { cn } from "@/lib/cn";

const statusTone: Record<string, "neutral" | "amber" | "emerald"> = {
  pending: "neutral",
  double_marking: "amber",
  reconciled: "emerald",
};

export default function GradingPage() {
  const [tasks, setTasks] = useState<GradingTaskRow[]>(demoGradingTasks);
  const [live, setLive] = useState(false);
  const [selected, setSelected] = useState<string | null>(null);

  useEffect(() => {
    listGradingTasks()
      .then((res) => { setTasks(res.data); setLive(true); if (res.data[0]) setSelected(res.data[0].id); })
      .catch(() => setSelected(demoGradingTasks[0]?.id ?? null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="flex items-center gap-2 text-3xl font-semibold tracking-tight">
          <PenLine className="h-7 w-7 text-violet-500 dark:text-violet-300" /> Grading queue
        </h1>
        <p className="mt-1 text-sm text-muted">
          Open-ended answers — double-marked independently, AI-assisted, reconciled to a final mark.
        </p>
      </div>

      <div className="grid gap-5 lg:grid-cols-[320px_1fr]">
        {/* Queue */}
        <div className="space-y-2">
          {tasks.length === 0 && <GlassCard className="p-6 text-center text-sm text-faint">Queue is empty 🎉</GlassCard>}
          {tasks.map((t) => (
            <button
              key={t.id}
              onClick={() => setSelected(t.id)}
              className={cn(
                "w-full rounded-xl border px-4 py-3 text-left transition",
                selected === t.id ? "border-violet-400/50 bg-violet-500/10" : "border-line glass hover:border-line-strong",
              )}
            >
              <div className="flex items-center justify-between">
                <span className="flex items-center gap-2 text-sm font-medium capitalize">
                  <FileText className="h-4 w-4 text-faint" /> {t.type.replace("_", " ")}
                </span>
                <Badge tone={statusTone[t.status] ?? "neutral"} className="capitalize">{t.status.replace("_", " ")}</Badge>
              </div>
              <p className="mt-1 truncate font-mono text-[11px] text-faint">{t.id}</p>
            </button>
          ))}
          {!live && <p className="px-1 pt-1 text-xs text-faint">Demo queue — sign in to grade live.</p>}
        </div>

        {/* Detail */}
        {selected ? <GradepanePanel id={selected} live={live} onChanged={(s) => setTasks((ts) => ts.map((t) => t.id === selected ? { ...t, status: s } : t))} /> : (
          <GlassCard className="grid place-items-center p-12 text-sm text-faint">Select a task to grade.</GlassCard>
        )}
      </div>
    </div>
  );
}

function GradepanePanel({ id, live, onChanged }: { id: string; live: boolean; onChanged: (status: string) => void }) {
  const [detail, setDetail] = useState<GradingTaskDetail | null>(null);
  const [maxMark, setMaxMark] = useState(10);
  const [mark, setMark] = useState("");
  const [finalMark, setFinalMark] = useState("");
  const [ai, setAi] = useState<{ mark: number; rationale: string } | null>(null);
  const [note, setNote] = useState<string | null>(null);

  useEffect(() => {
    setAi(null); setNote(null); setMark(""); setFinalMark("");
    getGradingTask(id).then(setDetail).catch(() => setDetail(demoGradingDetails[id] ?? null));
  }, [id]);

  if (!detail) return <GlassCard className="grid place-items-center p-12 text-sm text-faint">Loading…</GlassCard>;

  const humanMarks = detail.marks.filter((m) => !m.is_ai);

  async function suggest() {
    try {
      const r = await aiSuggestGrade(id, maxMark);
      setAi({ mark: r.mark, rationale: r.rationale });
    } catch {
      const words = (detail!.answer || "").split(/\s+/).length;
      setAi({ mark: Math.min(maxMark, Math.round((words / 40) * maxMark)), rationale: `Length-based estimate from ${words} words (demo).` });
    }
  }

  async function doMark() {
    const value = Number(mark);
    if (Number.isNaN(value)) return;
    try {
      const res = await submitGradeMark(id, value);
      const fresh = await getGradingTask(id);
      setDetail(fresh);
      onChanged(res.status);
      setNote(res.status === "reconciled" ? "Marks agreed — reconciled and folded into the score." : "Mark recorded; awaiting the second independent mark.");
    } catch (e) {
      // demo: append locally
      setDetail((d) => d ? { ...d, marks: [...d.marks, { grader_id: "you", mark: value, is_ai: false }] } : d);
      setNote(e instanceof ApiError ? e.message : "Sign in to record a live mark.");
    }
    setMark("");
  }

  async function doReconcile() {
    const value = Number(finalMark);
    if (Number.isNaN(value)) return;
    try {
      const res = await reconcileGrade(id, value);
      const fresh = await getGradingTask(id);
      setDetail(fresh);
      onChanged(res.status);
      setNote("Reconciled — final mark set and the score finalized.");
    } catch (e) {
      setNote(e instanceof ApiError ? e.message : "Sign in to reconcile live.");
    }
    setFinalMark("");
  }

  return (
    <GlassCard glow className="space-y-5 p-6">
      <div className="flex items-center justify-between">
        <Badge tone="violet" className="capitalize">{detail.task.type.replace("_", " ")}</Badge>
        <Badge tone={statusTone[detail.task.status] ?? "neutral"} className="capitalize">{detail.task.status.replace("_", " ")}</Badge>
      </div>

      <div>
        <p className="text-xs uppercase tracking-wide text-faint">Question</p>
        <h2 className="mt-1.5 text-lg font-medium leading-snug">{detail.question ?? "—"}</h2>
      </div>

      <div>
        <p className="text-xs uppercase tracking-wide text-faint">Candidate answer</p>
        <div className="mt-1.5 rounded-xl border border-line bg-black/[0.02] dark:bg-white/[0.03] p-4 text-sm leading-relaxed">
          {detail.answer || <span className="text-faint">No answer submitted.</span>}
        </div>
      </div>

      {/* Existing marks */}
      {detail.marks.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {detail.marks.map((m, i) => (
            <span key={i} className={cn(
              "inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm",
              m.is_ai ? "bg-sky-500/10 text-sky-700 dark:text-sky-200" : "bg-violet-500/10 text-violet-700 dark:text-violet-200",
            )}>
              {m.is_ai ? <Bot className="h-3.5 w-3.5" /> : <User className="h-3.5 w-3.5" />}
              {m.is_ai ? "AI" : "Marker"}: <strong>{m.mark}</strong>
            </span>
          ))}
        </div>
      )}

      {/* AI assist */}
      <div className="rounded-xl border border-sky-400/20 bg-sky-500/5 p-4">
        <div className="flex flex-wrap items-center gap-3">
          <span className="flex items-center gap-2 text-sm font-medium"><Sparkles className="h-4 w-4 text-sky-500 dark:text-sky-300" /> AI assist</span>
          <label className="flex items-center gap-2 text-xs text-muted">
            out of
            <input type="number" value={maxMark} onChange={(e) => setMaxMark(Number(e.target.value))} className="h-8 w-16 rounded-lg border border-line bg-bg-soft px-2 text-sm outline-none" />
          </label>
          <Button variant="glass" size="sm" onClick={suggest}>Suggest</Button>
          <Badge tone="sky">advisory only</Badge>
        </div>
        <AnimatePresence>
          {ai && (
            <motion.p initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} className="mt-3 text-sm">
              Suggested <strong className="text-sky-700 dark:text-sky-200">{ai.mark}</strong> / {maxMark} — <span className="text-muted">{ai.rationale}</span>
            </motion.p>
          )}
        </AnimatePresence>
      </div>

      {/* Actions */}
      {detail.task.status === "reconciled" ? (
        <div className="flex items-center gap-2 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-200">
          <Check className="h-4 w-4" /> Reconciled · final mark {detail.task.final_mark}
        </div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="rounded-xl border border-line p-4">
            <p className="text-sm font-medium">Submit a mark</p>
            <p className="mt-0.5 text-xs text-faint">{humanMarks.length}/2 independent marks recorded</p>
            <div className="mt-3 flex gap-2">
              <input type="number" value={mark} onChange={(e) => setMark(e.target.value)} placeholder="0" className="h-10 w-24 rounded-lg border border-line bg-bg-soft px-3 text-sm outline-none focus:border-violet-400/50" />
              <Button onClick={doMark} disabled={mark === ""}>Submit</Button>
            </div>
          </div>
          <div className={cn("rounded-xl border p-4", detail.task.status === "double_marking" ? "border-amber-400/30" : "border-line opacity-60")}>
            <p className="flex items-center gap-1.5 text-sm font-medium"><Scale className="h-4 w-4" /> Senior reconcile</p>
            <p className="mt-0.5 text-xs text-faint">{detail.task.status === "double_marking" ? "Marks diverged — set the final mark" : "Available once two marks diverge"}</p>
            <div className="mt-3 flex gap-2">
              <input type="number" value={finalMark} onChange={(e) => setFinalMark(e.target.value)} placeholder="0" disabled={detail.task.status !== "double_marking"} className="h-10 w-24 rounded-lg border border-line bg-bg-soft px-3 text-sm outline-none focus:border-violet-400/50 disabled:opacity-50" />
              <Button variant="glass" onClick={doReconcile} disabled={detail.task.status !== "double_marking" || finalMark === ""}>Reconcile</Button>
            </div>
          </div>
        </div>
      )}

      {note && <p className="rounded-lg bg-white/5 px-3 py-2 text-xs text-muted">{note}</p>}
      {!live && <p className="text-center text-xs text-faint">Demo mode — actions are simulated until you sign in.</p>}
    </GlassCard>
  );
}
