-- CreateTable
CREATE TABLE "PatientSickLeave" (
    "id" TEXT NOT NULL,
    "patientId" TEXT NOT NULL,
    "startDate" DATE NOT NULL,
    "endDate" DATE NOT NULL,
    "reason" TEXT,
    "note" TEXT,
    "recordedById" TEXT NOT NULL,
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMPTZ NOT NULL,

    CONSTRAINT "PatientSickLeave_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "PatientSickLeave_patientId_fkey" FOREIGN KEY ("patientId") REFERENCES "Patient"("id") ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT "PatientSickLeave_recordedById_fkey" FOREIGN KEY ("recordedById") REFERENCES "User"("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX "PatientSickLeave_patientId_startDate_idx" ON "PatientSickLeave"("patientId", "startDate");
