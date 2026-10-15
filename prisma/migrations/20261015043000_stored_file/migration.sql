-- CreateTable
CREATE TABLE "StoredFile" (
    "id" TEXT NOT NULL,
    "key" TEXT NOT NULL,
    "mimeType" TEXT NOT NULL,
    "data" BYTEA NOT NULL,
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "StoredFile_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "StoredFile_key_key" UNIQUE ("key")
);

CREATE INDEX "StoredFile_createdAt_idx" ON "StoredFile"("createdAt");
