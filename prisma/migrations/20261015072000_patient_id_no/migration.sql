-- AlterTable
ALTER TABLE "Patient" ADD COLUMN "idNo" TEXT;

-- CreateIndex
CREATE INDEX "Patient_idNo_idx" ON "Patient"("idNo");
