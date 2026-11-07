import { getRequestURL, proxyRequest, type H3Event } from 'h3';

export function proxyToService(event: H3Event, prefix: string, target: string) {
  const requestUrl = getRequestURL(event);
  const upstreamPath = requestUrl.pathname.slice(prefix.length) || '/';
  const upstreamUrl = `${target.replace(/\/$/, '')}${upstreamPath}${requestUrl.search}`;

  return proxyRequest(event, upstreamUrl);
}
