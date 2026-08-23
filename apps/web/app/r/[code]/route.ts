/**
 * Referral short link: /r/{code} -> /signup?ref={code}. The code is the
 * referrer's 6-digit Manfaa code; anything that is not exactly six digits
 * falls through to a plain /signup rather than carrying garbage into the
 * form.
 *
 * The Location is deliberately RELATIVE: nginx proxies without rewriting
 * the Host header, so any absolute URL built from the request resolves to
 * the internal origin (localhost:3300) — a shared link would strand the
 * friend there. Browsers resolve a relative Location against the public
 * URL they actually visited (RFC 9110 §10.2.2).
 */
export async function GET(
  _request: Request,
  { params }: { params: Promise<{ code: string }> },
) {
  const { code } = await params;
  const target = /^\d{6}$/.test(code) ? `/signup?ref=${code}` : '/signup';

  return new Response(null, { status: 307, headers: { Location: target } });
}
