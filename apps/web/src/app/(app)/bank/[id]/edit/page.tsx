"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { QuestionComposer } from "@/components/questions/QuestionComposer";

export default function EditQuestionPage() {
  const { id } = useParams<{ id: string }>();

  return (
    <div className="space-y-7">
      <div>
        <Link href="/banks" className="inline-flex items-center gap-2 text-sm text-faint hover:text-ink">
          <ArrowLeft className="h-4 w-4" /> Question Banks
        </Link>
        <h1 className="mt-2 text-3xl font-semibold tracking-tight">Modify question</h1>
        <p className="mt-1 text-sm text-muted">Saving creates a new immutable version; published papers keep the version they pinned.</p>
      </div>
      <QuestionComposer editItemId={id} />
    </div>
  );
}
