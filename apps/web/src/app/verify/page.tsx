"use client";

import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import Link from "next/link";
import { motion } from "motion/react";
import { ShieldCheck, ShieldX, Search, Link2, ArrowLeft } from "lucide-react";
import { Logo } from "@/components/brand/Logo";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { verifyCertificate, type CertificateVerification } from "@/lib/api";

function VerifyInner() {
  const params = useSearchParams();
  const [token, setToken] = useState(params.get("token") ?? "");
  const [result, setResult] = useState<CertificateVerification | null>(null);
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function run(t: string) {
    if (!t.trim()) return;
    setLoading(true);
    setErr(null);
    setResult(null);
    try {
      const { data } = await verifyCertificate(t.trim());
      setResult(data);
    } catch {
      setErr("Can't reach the verification service. Is the API running?");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (params.get("token")) run(params.get("token")!);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="mx-auto flex min-h-screen max-w-xl flex-col px-5 py-8">
      <div className="flex items-center justify-between">
        <Link href="/" className="inline-flex items-center gap-2 text-sm text-faint hover:text-ink">
          <ArrowLeft className="h-4 w-4" /> Back
        </Link>
        <ThemeToggle />
      </div>

      <div className="mt-10 text-center">
        <div className="mx-auto inline-flex"><Logo /></div>
        <h1 className="mt-6 text-3xl font-semibold tracking-tight">Certificate verification</h1>
        <p className="mt-2 text-muted">
          Enter a verification token to confirm a credential&apos;s authenticity. No account needed —
          the check is cryptographic and doesn&apos;t rely on trusting the issuer&apos;s database.
        </p>
      </div>

      <form
        onSubmit={(e) => { e.preventDefault(); run(token); }}
        className="mt-8 flex flex-col gap-3 sm:flex-row"
      >
        <label className="glass flex h-12 flex-1 items-center gap-3 rounded-xl px-3.5 focus-within:border-violet-400/40 transition">
          <Search className="h-[18px] w-[18px] text-faint" />
          <input
            value={token}
            onChange={(e) => setToken(e.target.value)}
            placeholder="Verification token"
            className="w-full bg-transparent font-mono text-sm outline-none placeholder:text-faint"
          />
        </label>
        <Button type="submit" disabled={loading}>{loading ? "Verifying…" : "Verify"}</Button>
      </form>

      {err && (
        <p className="mt-4 rounded-lg border border-rose-400/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-700 dark:text-rose-200">{err}</p>
      )}

      {result && (
        <motion.div
          initial={{ opacity: 0, y: 14 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
          className="glass ring-gradient mt-6 overflow-hidden rounded-2xl"
        >
          <div className={result.valid ? "bg-emerald-500/10 px-6 py-5" : "bg-rose-500/10 px-6 py-5"}>
            <div className="flex items-center gap-3">
              {result.valid ? (
                <ShieldCheck className="h-8 w-8 text-emerald-600 dark:text-emerald-300" />
              ) : (
                <ShieldX className="h-8 w-8 text-rose-600 dark:text-rose-300" />
              )}
              <div>
                <div className="text-lg font-semibold">
                  {result.valid ? "Authentic certificate" : "Not valid"}
                </div>
                {!result.valid && result.reason && (
                  <div className="text-sm text-rose-700 dark:text-rose-200 capitalize">{result.reason.replace(/_/g, " ")}</div>
                )}
              </div>
              {result.anchored && <Badge tone="sky" className="ml-auto"><Link2 className="h-3 w-3" /> Anchored</Badge>}
            </div>
          </div>

          {result.valid && result.payload && (
            <dl className="divide-y divide-white/5 px-6 py-2">
              <Row k="Candidate" v={result.payload.candidate?.name} />
              <Row k="Assessment" v={result.payload.assessment?.title} />
              <Row k="Type" v={result.payload.assessment?.kind} cap />
              <Row k="Raw score" v={result.payload.result?.raw_score?.toString()} />
              <Row k="Serial" v={result.serial} mono />
              <Row k="Issued" v={result.issued_at ? new Date(result.issued_at).toLocaleString() : undefined} />
            </dl>
          )}
        </motion.div>
      )}
    </div>
  );
}

function Row({ k, v, mono, cap }: { k: string; v?: string; mono?: boolean; cap?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-4 py-3 text-sm">
      <dt className="text-faint">{k}</dt>
      <dd className={`${mono ? "font-mono text-xs" : ""} ${cap ? "capitalize" : ""} text-right`}>{v ?? "—"}</dd>
    </div>
  );
}

export default function VerifyPage() {
  return (
    <Suspense fallback={null}>
      <VerifyInner />
    </Suspense>
  );
}
