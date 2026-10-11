-- CreateEnum
CREATE TYPE "CampaignDeliveryMode" AS ENUM ('INDIVIDUAL', 'GROUP');

-- CreateEnum
CREATE TYPE "CampaignBatchStatus" AS ENUM ('PENDING', 'SENT', 'SKIPPED');

-- CreateEnum
CREATE TYPE "MessageLibraryCategory" AS ENUM ('REMINDER', 'GREETING', 'ANNOUNCEMENT', 'FOLLOWUP', 'OTHER');

-- AlterTable
ALTER TABLE "Campaign" ADD COLUMN     "deliveryMode" "CampaignDeliveryMode" NOT NULL DEFAULT 'INDIVIDUAL',
ADD COLUMN     "libraryItemId" TEXT;

-- AlterTable
ALTER TABLE "MessageTask" ADD COLUMN     "campaignBatchId" TEXT;

-- CreateTable
CREATE TABLE "CampaignBatch" (
    "id" TEXT NOT NULL,
    "campaignId" TEXT NOT NULL,
    "batchNumber" INTEGER NOT NULL,
    "size" INTEGER NOT NULL,
    "status" "CampaignBatchStatus" NOT NULL DEFAULT 'PENDING',
    "handledBy" TEXT,
    "handledAt" TIMESTAMPTZ,
    "skipReason" TEXT,
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMPTZ NOT NULL,

    CONSTRAINT "CampaignBatch_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "MessageDraft" (
    "id" TEXT NOT NULL,
    "createdBy" TEXT NOT NULL,
    "title" TEXT,
    "body" TEXT NOT NULL,
    "language" TEXT NOT NULL DEFAULT 'en',
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMPTZ NOT NULL,

    CONSTRAINT "MessageDraft_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "MessageLibraryItem" (
    "id" TEXT NOT NULL,
    "title" TEXT NOT NULL,
    "body" TEXT NOT NULL,
    "language" TEXT NOT NULL,
    "category" "MessageLibraryCategory" NOT NULL DEFAULT 'REMINDER',
    "normalizedHash" TEXT NOT NULL,
    "isFavorite" BOOLEAN NOT NULL DEFAULT false,
    "useCount" INTEGER NOT NULL DEFAULT 0,
    "lastUsedAt" TIMESTAMPTZ,
    "createdBy" TEXT NOT NULL,
    "active" BOOLEAN NOT NULL DEFAULT true,
    "pairId" TEXT,
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMPTZ NOT NULL,

    CONSTRAINT "MessageLibraryItem_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Audience" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "filterJson" JSONB NOT NULL,
    "createdBy" TEXT NOT NULL,
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMPTZ NOT NULL,

    CONSTRAINT "Audience_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "CampaignBatch_campaignId_idx" ON "CampaignBatch"("campaignId");

-- CreateIndex
CREATE INDEX "CampaignBatch_status_idx" ON "CampaignBatch"("status");

-- CreateIndex
CREATE UNIQUE INDEX "CampaignBatch_campaignId_batchNumber_key" ON "CampaignBatch"("campaignId", "batchNumber");

-- CreateIndex
CREATE INDEX "MessageDraft_createdBy_updatedAt_idx" ON "MessageDraft"("createdBy", "updatedAt");

-- CreateIndex
CREATE INDEX "MessageLibraryItem_language_category_idx" ON "MessageLibraryItem"("language", "category");

-- CreateIndex
CREATE INDEX "MessageLibraryItem_isFavorite_active_idx" ON "MessageLibraryItem"("isFavorite", "active");

-- CreateIndex
CREATE INDEX "MessageLibraryItem_useCount_idx" ON "MessageLibraryItem"("useCount");

-- CreateIndex
CREATE INDEX "MessageLibraryItem_lastUsedAt_idx" ON "MessageLibraryItem"("lastUsedAt");

-- CreateIndex
CREATE INDEX "MessageLibraryItem_pairId_idx" ON "MessageLibraryItem"("pairId");

-- CreateIndex
CREATE UNIQUE INDEX "MessageLibraryItem_normalizedHash_language_key" ON "MessageLibraryItem"("normalizedHash", "language");

-- CreateIndex
CREATE INDEX "Audience_createdBy_idx" ON "Audience"("createdBy");

-- CreateIndex
CREATE INDEX "MessageTask_campaignBatchId_idx" ON "MessageTask"("campaignBatchId");

-- AddForeignKey
ALTER TABLE "MessageTask" ADD CONSTRAINT "MessageTask_campaignBatchId_fkey" FOREIGN KEY ("campaignBatchId") REFERENCES "CampaignBatch"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Campaign" ADD CONSTRAINT "Campaign_libraryItemId_fkey" FOREIGN KEY ("libraryItemId") REFERENCES "MessageLibraryItem"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "CampaignBatch" ADD CONSTRAINT "CampaignBatch_campaignId_fkey" FOREIGN KEY ("campaignId") REFERENCES "Campaign"("id") ON DELETE CASCADE ON UPDATE CASCADE;
