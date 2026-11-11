// Push notifications are not part of v2 yet; accept and ignore so old clients don't error.
export const POST = () => new Response(null, { status: 204 });
