-- AlterTable
ALTER TABLE "Service" ADD COLUMN     "nameEn" TEXT NOT NULL DEFAULT '',
ADD COLUMN     "receiptLabelEn" TEXT,
ADD COLUMN     "receiptLabelAm" TEXT,
ADD COLUMN     "displayOrder" INTEGER NOT NULL DEFAULT 0;

-- Backfill nameEn from the existing English display name.
UPDATE "Service" SET "nameEn" = "name" WHERE "nameEn" = '';

-- AlterTable
ALTER TABLE "Service" ALTER COLUMN "isPublic" SET DEFAULT true;