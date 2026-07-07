"use client";

import { useParams } from "next/navigation";
import { SchedulingWorkspace } from "@/components/scheduling/SchedulingWorkspace";

export default function AssessmentSchedulingPage() {
  const { id } = useParams<{ id: string }>();
  return <SchedulingWorkspace assessmentId={id} />;
}
