<?php

namespace Tests\Unit;

use App\Services\NexusDocument;
use PHPUnit\Framework\TestCase;

class NexusDocumentTest extends TestCase
{
    public function test_it_maps_the_actual_fastapi_document_response(): void
    {
        $document = NexusDocument::fromApi([
            'id' => 'document-1',
            'project_id' => 'project-1',
            'filename' => 'safe-report.pdf',
            'original_filename' => 'Report.pdf',
            'file_size' => 1536,
            'status' => 'ready',
            'chunks_count' => 12,
            'error' => null,
            'created_at' => '2026-10-16T09:00:00+00:00',
            'processed_at' => '2026-10-16T09:01:00+00:00',
        ]);

        $this->assertSame('Report.pdf', $document->name);
        $this->assertSame(1536, $document->fileSize);
        $this->assertSame(12, $document->chunkCount);
        $this->assertSame('2026-10-16T09:00:00+00:00', $document->uploadedAt);
        $this->assertSame('2026-10-16T09:01:00+00:00', $document->updatedAt);
    }
}
