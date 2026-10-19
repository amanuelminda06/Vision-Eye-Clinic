-- The website booking form is auto-approved: every submission is inserted as a
-- real Appointment straight away, so the "appointment request" queue (and its
-- status enum) no longer exists.

-- DropForeignKey
ALTER TABLE "AppointmentRequest" DROP CONSTRAINT "AppointmentRequest_patientId_fkey";

-- DropForeignKey
ALTER TABLE "AppointmentRequest" DROP CONSTRAINT "AppointmentRequest_appointmentId_fkey";

-- DropTable
DROP TABLE "AppointmentRequest";

-- DropEnum
DROP TYPE "RequestStatus";
