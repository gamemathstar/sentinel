"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { motion } from "motion/react";
import { Mail, Lock, ArrowRight, ShieldCheck } from "lucide-react";
import { Logo } from "@/components/brand/Logo";
import { Button } from "@/components/ui/Button";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { login, ApiError } from "@/lib/api";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("officer@demo.legion.test");
  const [password, setPassword] = useState("password");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [mfa, setMfa] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const res = await login(email, password);
      if (res.status === "mfa_required") {
        setMfa(true);
      } else {
        router.push("/dashboard");
      }
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.status === 0 || err.message.includes("fetch")
            ? "Can't reach the API. Start it with `php artisan serve`."
            : err.message
          : "Something went wrong.",
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="relative grid min-h-screen lg:grid-cols-2">
      <ThemeToggle className="absolute right-5 top-5 z-10" />
      {/* Left — brand panel */}
      <div className="relative hidden lg:flex flex-col justify-between overflow-hidden border-r border-line p-12">
        <Logo />
        <div className="max-w-md">
          <motion.h1
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
            className="text-4xl font-semibold leading-tight tracking-tight"
          >
            The assessment <span className="gradient-text">operating system</span> for
            high-stakes exams.
          </motion.h1>
          <p className="mt-4 text-muted">
            Author, deliver, proctor, score, analyze, and certify — from a single secure
            platform built to scale to a million candidates.
          </p>
          <div className="mt-8 flex flex-wrap gap-2">
            {["Split-key answer vault", "AI proctoring", "IRT analytics", "Verifiable certs"].map((t) => (
              <span key={t} className="glass rounded-full px-3 py-1.5 text-xs text-muted">
                {t}
              </span>
            ))}
          </div>
        </div>
        <div className="flex items-center gap-2 text-xs text-faint">
          <ShieldCheck className="h-4 w-4 text-emerald-400" /> Zero-trust · encrypted at rest & in transit
        </div>
      </div>

      {/* Right — form */}
      <div className="flex items-center justify-center p-6 sm:p-12">
        <motion.div
          initial={{ opacity: 0, y: 18 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
          className="glass ring-gradient w-full max-w-sm rounded-2xl p-8"
        >
          <div className="lg:hidden mb-6">
            <Logo />
          </div>
          <h2 className="text-2xl font-semibold tracking-tight">Sign in</h2>
          <p className="mt-1 text-sm text-muted">Welcome back to Legion CBT.</p>

          <form onSubmit={submit} className="mt-7 space-y-4">
            <Field icon={Mail} type="email" placeholder="you@institution.edu" value={email} onChange={setEmail} />
            <Field icon={Lock} type="password" placeholder="Password" value={password} onChange={setPassword} />

            {mfa && (
              <Field icon={ShieldCheck} type="text" placeholder="6-digit MFA code" value="" onChange={() => {}} />
            )}

            {error && (
              <p className="rounded-lg border border-rose-400/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-700 dark:text-rose-200">
                {error}
              </p>
            )}

            <Button type="submit" disabled={loading} className="w-full">
              {loading ? "Signing in…" : "Sign in"}
              {!loading && <ArrowRight className="h-4 w-4" />}
            </Button>
          </form>

          <p className="mt-6 text-center text-xs text-faint">
            Need to verify a certificate?{" "}
            <Link href="/verify" className="text-violet-600 dark:text-violet-300 hover:underline">
              Verification portal
            </Link>
          </p>
        </motion.div>
      </div>
    </div>
  );
}

function Field({
  icon: Icon,
  ...props
}: {
  icon: React.ElementType;
  type: string;
  placeholder: string;
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <label className="glass flex h-12 items-center gap-3 rounded-xl px-3.5 focus-within:border-violet-400/40 transition">
      <Icon className="h-[18px] w-[18px] text-faint" />
      <input
        type={props.type}
        placeholder={props.placeholder}
        value={props.value}
        onChange={(e) => props.onChange(e.target.value)}
        className="w-full bg-transparent text-sm outline-none placeholder:text-faint"
      />
    </label>
  );
}
