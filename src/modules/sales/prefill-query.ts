/**
 * Pure, client-safe part of the sales-prefill seam (no prisma / server
 * imports) so components like "Record sale" can build the query string without
 * dragging server code into the client bundle.
 */

export type SalePrefillQueryParams = {
  patientId?: string | null;
  doctorId?: string | null;
  serviceId?: string | null;
  appointmentId?: string | null;
};

export function salePrefillQuery(params: SalePrefillQueryParams): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value) query.set(key, value);
  }
  const serialized = query.toString();
  return serialized ? `?${serialized}` : '';
}
