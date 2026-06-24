import { redirect } from "next/navigation";

// Questions are browsed within their bank now (docs/18). This route just forwards.
export default function BankIndexRedirect() {
  redirect("/banks");
}
