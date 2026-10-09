/**
 * Analytics placeholder.
 *
 * NO analytics provider is loaded until the clinic chooses one. To enable,
 * set a provider (e.g. Plausible, Umami or GA4) here — the rest of the site
 * already renders this component once per page, in the public layout.
 *
 * Deliberately renders nothing today: no cookies, no third-party requests.
 */
export default function Analytics() {
  // TODO(analytics): when a provider is chosen, load its script here and add
  // its origin to the CSP `script-src`/`connect-src` in next.config.ts.
  return null;
}
