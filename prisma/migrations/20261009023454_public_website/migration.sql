-- AlterTable
ALTER TABLE "Service" ADD COLUMN     "isPublic" BOOLEAN NOT NULL DEFAULT false,
ADD COLUMN     "publicDescriptionAm" TEXT,
ADD COLUMN     "publicDescriptionEn" TEXT;

-- CreateTable
CREATE TABLE "SiteContent" (
    "key" TEXT NOT NULL,
    "valueEn" TEXT NOT NULL,
    "valueAm" TEXT NOT NULL,
    "updatedBy" TEXT,
    "updatedAt" TIMESTAMPTZ NOT NULL,

    CONSTRAINT "SiteContent_pkey" PRIMARY KEY ("key")
);
