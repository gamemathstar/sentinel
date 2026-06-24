"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { ArrowLeft, Upload } from "lucide-react";
import { GlassCard } from "@/components/ui/GlassCard";
import { Button } from "@/components/ui/Button";
import { Segmented } from "@/components/ui/Segmented";
import { QuestionComposer } from "@/components/questions/QuestionComposer";
import { importQuestions, ApiError, type ImportSummary } from "@/lib/api";
import { cn } from "@/lib/cn";

function NewItemInner() {
  const params = useSearchParams();
  const presetBank = params.get("bank") ?? undefined;
  const [tab, setTab] = useState<"compose" | "import">("compose");

  return (
    <div className="space-y-7">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <Link href={presetBank ? `/banks/${presetBank}` : "/banks"} className="inline-flex items-center gap-2 text-sm text-faint hover:text-ink">
            <ArrowLeft className="h-4 w-4" /> Back to bank
          </Link>
          <h1 className="mt-2 text-3xl font-semibold tracking-tight">Compose a question</h1>
        </div>
        <Segmented value={tab} onChange={setTab} options={[{ value: "compose", label: "Compose" }, { value: "import", label: "Import" }]} />
      </div>
      {tab === "compose" ? <QuestionComposer presetBankId={presetBank} /> : <Importer />}
    </div>
  );
}

export default function NewItemPage() {
  return (
    <Suspense fallback={null}>
      <NewItemInner />
    </Suspense>
  );
}

/* ──────────────────────────── Import ──────────────────────────── */

const SAMPLES: Record<string, string> = {
  legion: `?? What is the capital of Nigeria? {Easy}\n** Lagos\n** Abuja ==\n** Kano\n\n?? Select the prime numbers {Hard}\n** 2 ==\n** 4\n** 5 ==`,
  aiken: `What is the capital of France?\nA. London\nB. Paris\nC. Berlin\nANSWER: B`,
  gift: `::Capital:: Who is buried in Grant's tomb? {~no one =Ulysses S. Grant ~Napoleon}\n\nThe earth is flat. {FALSE}`,
};

function Importer() {
  const [format, setFormat] = useState<"legion" | "aiken" | "gift">("legion");
  const [body, setBody] = useState(SAMPLES.legion);
  const [busy, setBusy] = useState(false);
  const [summary, setSummary] = useState<ImportSummary | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function run() {
    setError(null); setSummary(null); setBusy(true);
    try {
      setSummary(await importQuestions(format, body));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Couldn't reach the API — sign in and start `php artisan serve`.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
      <GlassCard className="space-y-4 p-6">
        <div className="flex items-center justify-between">
          <Segmented value={format} onChange={(f) => { setFormat(f as typeof format); setBody(SAMPLES[f]); }}
            options={[{ value: "legion", label: "Legion" }, { value: "aiken", label: "Aiken" }, { value: "gift", label: "GIFT" }]} />
          <button onClick={() => setBody(SAMPLES[format])} className="text-xs text-violet-600 dark:text-violet-300 hover:underline">Load sample</button>
        </div>
        <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={16} spellCheck={false}
          className="w-full resize-none rounded-xl border border-line bg-black/[0.02] dark:bg-white/[0.03] p-4 font-mono text-sm leading-relaxed outline-none focus:border-violet-400/50" />
        {error && <p className="rounded-lg border border-rose-400/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-700 dark:text-rose-200">{error}</p>}
        <Button onClick={run} disabled={busy}>{busy ? "Importing…" : "Validate & import"} {!busy && <Upload className="h-4 w-4" />}</Button>
      </GlassCard>

      <GlassCard glow className="p-6">
        <h3 className="text-sm font-semibold">Import result</h3>
        {!summary ? (
          <p className="mt-3 text-sm text-faint">Bulk-validated before import — duplicates detected and malformed rows reported per-row, never aborting the batch. Note: imports land in your default bank.</p>
        ) : (
          <div className="mt-4 space-y-4">
            <div className="grid grid-cols-3 gap-2 text-center">
              <Stat n={summary.created} label="Created" tone="emerald" />
              <Stat n={summary.duplicates} label="Duplicate" tone="amber" />
              <Stat n={summary.errors} label="Errors" tone="rose" />
            </div>
            {summary.results.filter((r) => r.status === "error").map((r) => (
              <p key={r.index} className="rounded-lg bg-rose-500/10 px-3 py-2 text-xs text-rose-700 dark:text-rose-200">Row {r.index + 1}: {r.message}</p>
            ))}
          </div>
        )}
      </GlassCard>
    </div>
  );
}

function Stat({ n, label, tone }: { n: number; label: string; tone: "emerald" | "amber" | "rose" }) {
  const color = { emerald: "text-emerald-500 dark:text-emerald-400", amber: "text-amber-500 dark:text-amber-400", rose: "text-rose-500 dark:text-rose-400" }[tone];
  return <div className="glass rounded-xl py-3"><div className={cn("text-2xl font-semibold", color)}>{n}</div><div className="text-xs text-faint">{label}</div></div>;
}
