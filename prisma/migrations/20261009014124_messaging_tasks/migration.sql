-- CreateEnum
CREATE TYPE "MessageTemplateKey" AS ENUM ('APPT_REMINDER', 'APPT_CONFIRMATION', 'APPT_SAME_DAY', 'NO_SHOW', 'SCHEDULE_CHANGE', 'FOLLOWUP_DUE', 'RECALL', 'GLASSES_READY', 'THANK_YOU', 'BIRTHDAY', 'WELCOME', 'CLOSURE_NOTICE', 'CUSTOM');

-- CreateEnum
CREATE TYPE "MessageSourceType" AS ENUM ('APPOINTMENT', 'FOLLOWUP', 'BIRTHDAY', 'RECALL', 'CAMPAIGN', 'MANUAL');

-- CreateEnum
CREATE TYPE "MessageStatus" AS ENUM ('PENDING', 'OPENED', 'SENT', 'SKIPPED', 'EXPIRED', 'CANCELLED', 'BLOCKED');

-- CreateEnum
CREATE TYPE "CampaignStatus" AS ENUM ('DRAFT', 'ACTIVE', 'COMPLETED', 'CANCELLED');

-- CreateTable
CREATE TABLE "MessageTemplate" (
    "id" TEXT NOT NULL,
    "key" "MessageTemplateKey" NOT NULL,
    "language" TEXT NOT NULL,
    "body" TEXT NOT NULL,
    "active" BOOLEAN NOT NULL DEFAULT true,
    "updatedBy" TEXT,
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMPTZ NOT NULL,

    CONSTRAINT "MessageTemplate_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "MessageTask" (
    "id" TEXT NOT NULL,
    "patientId" TEXT NOT NULL,
    "templateKey" "MessageTemplateKey" NOT NULL,
    "sourceType" "MessageSourceType" NOT NULL,
    "sourceId" TEXT NOT NULL,
    "campaignId" TEXT,
    "language" TEXT NOT NULL,
    "renderedMessage" TEXT NOT NULL,
    "dueAt" TIMESTAMPTZ NOT NULL,
    "sendBy" TIMESTAMPTZ,
    "expiresAt" TIMESTAMPTZ,
    "status" "MessageStatus" NOT NULL DEFAULT 'PENDING',
    "blockedReason" TEXT,
    "skipReason" TEXT,
    "handledBy" TEXT,
    "handledAt" TIMESTAMPTZ,
    "editedBy" TEXT,
    "editedAt" TIMESTAMPTZ,
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "MessageTask_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Campaign" (
    "id" TEXT NOT NULL,
    "title" TEXT NOT NULL,
    "templateKey" "MessageTemplateKey",
    "customBody" TEXT,
    "languageMode" TEXT NOT NULL,
    "fixedLanguage" TEXT,
    "filterJson" JSONB NOT NULL,
    "status" "CampaignStatus" NOT NULL DEFAULT 'DRAFT',
    "expiresAt" TIMESTAMPTZ,
    "createdBy" TEXT NOT NULL,
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMPTZ NOT NULL,

    CONSTRAINT "Campaign_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "MessageTemplate_key_language_key" ON "MessageTemplate"("key", "language");

-- CreateIndex
CREATE INDEX "MessageTask_status_dueAt_idx" ON "MessageTask"("status", "dueAt");

-- CreateIndex
CREATE INDEX "MessageTask_patientId_createdAt_idx" ON "MessageTask"("patientId", "createdAt");

-- CreateIndex
CREATE INDEX "MessageTask_campaignId_idx" ON "MessageTask"("campaignId");

-- CreateIndex
CREATE INDEX "MessageTask_status_expiresAt_idx" ON "MessageTask"("status", "expiresAt");

-- CreateIndex
CREATE UNIQUE INDEX "MessageTask_sourceType_sourceId_templateKey_patientId_key" ON "MessageTask"("sourceType", "sourceId", "templateKey", "patientId");

-- AddForeignKey
ALTER TABLE "MessageTask" ADD CONSTRAINT "MessageTask_patientId_fkey" FOREIGN KEY ("patientId") REFERENCES "Patient"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "MessageTask" ADD CONSTRAINT "MessageTask_campaignId_fkey" FOREIGN KEY ("campaignId") REFERENCES "Campaign"("id") ON DELETE SET NULL ON UPDATE CASCADE;
