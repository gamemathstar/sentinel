import { Award } from "lucide-react";
import { SectionStub } from "@/components/ui/SectionStub";

export default function CertificatesPage() {
  return (
    <SectionStub
      icon={Award}
      title="Certificates"
      blurb="Issued credentials, revocation, and the public verification portal."
      points={[
        "POST /api/certification/sittings/{id}/issue",
        "GET /api/certification/certificates",
        "POST /api/certification/certificates/{id}/revoke",
        "GET /api/certification/verify/{token}",
      ]}
    />
  );
}
