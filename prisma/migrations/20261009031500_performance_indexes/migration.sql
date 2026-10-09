-- Performance indexes for the hot list/report/search paths.
-- Added with the final slice: browse/search/filter screens and the daily
-- summary all filter on these columns under load.

-- HR: "active doctors on duty" and staff pickers.
CREATE INDEX "Staff_active_isDoctor_idx" ON "Staff"("active", "isDoctor");

-- Patients: active filter and "newest first" list.
CREATE INDEX "Patient_active_idx" ON "Patient"("active");
CREATE INDEX "Patient_createdAt_idx" ON "Patient"("createdAt");

-- Appointments: day/week boards range-scan on startsAt.
CREATE INDEX "Appointment_startsAt_idx" ON "Appointment"("startsAt");

-- Sales: payment-method filter + the pending-voids inbox.
CREATE INDEX "Sale_paymentMethod_idx" ON "Sale"("paymentMethod");
CREATE INDEX "Sale_status_voidRequestedBy_idx" ON "Sale"("status", "voidRequestedBy");

-- Audit: filtered audit-trail browsing (entity + action + date).
CREATE INDEX "AuditLog_entity_action_createdAt_idx" ON "AuditLog"("entity", "action", "createdAt");
