"use client";

import { useParams } from "next/navigation";
import { LiveMonitor } from "@/components/exams/LiveMonitor";

export default function AssessmentLivePage() {
  const { id } = useParams<{ id: string }>();
  return <LiveMonitor assessmentId={id} />;
}
