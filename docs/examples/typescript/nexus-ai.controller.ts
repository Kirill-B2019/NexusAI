/**
 * NEXUS AI — Nest.js контроллер.
 *
 * Все маршруты проксируют запросы в NEXUS AI.
 * UI никогда не видит API-ключ NEXUS AI напрямую.
 *
 * Использование в UI:
 *   fetch('/nexus/chat', { method: 'POST', body: JSON.stringify({...}) })
 */

import {
  Controller,
  Post,
  Get,
  Body,
  Param,
  Query,
  Res,
  HttpCode,
  Logger,
} from '@nestjs/common';
import { Response } from 'express';
import { NexusAiService, ChatDto } from './nexus-ai.service';

@Controller('nexus')
export class NexusAiController {
  private readonly logger = new Logger(NexusAiController.name);

  constructor(private readonly nexus: NexusAiService) {}

  // ─── System ─────────────────────────────────────────────

  @Get('health')
  async health() {
    return this.nexus.health();
  }

  // ─── Experts ────────────────────────────────────────────

  @Get('experts')
  async listExperts() {
    return this.nexus.listExperts();
  }

  // ─── Projects ───────────────────────────────────────────

  @Get('projects')
  async listProjects() {
    return this.nexus.listProjects();
  }

  @Post('projects')
  async createProject(@Body() body: { name: string; external_id?: string }) {
    return this.nexus.createProject(body.name, body.external_id);
  }

  // ─── Chat ───────────────────────────────────────────────

  @Post('chat')
  @HttpCode(200)
  async chat(@Body() dto: ChatDto) {
    this.logger.log(`Chat request: ${dto.message.slice(0, 80)}...`);
    return this.nexus.chat(dto);
  }

  /**
   * SSE-стрим. Проксирует события от NEXUS AI в браузер.
   *
   * В nginx/laravel-прокси должно быть:
   *   proxy_buffering off;
   *   proxy_cache off;
   */
  @Post('chat/stream')
  async streamChat(@Body() dto: ChatDto, @Res() res: Response) {
    res.setHeader('Content-Type', 'text/event-stream');
    res.setHeader('Cache-Control', 'no-cache');
    res.setHeader('Connection', 'keep-alive');
    res.setHeader('X-Accel-Buffering', 'no');

    this.logger.log(`Stream request: ${dto.message.slice(0, 80)}...`);

    const subscription = this.nexus.streamChat(dto).subscribe({
      next: (event) => {
        res.write(`event: ${event.type}\n`);
        res.write(`data: ${JSON.stringify(event.data)}\n\n`);
      },
      error: (err) => {
        this.logger.error(`Stream error: ${err.message}`);
        res.write(`event: error\ndata: ${JSON.stringify({ error: err.message })}\n\n`);
        res.end();
      },
      complete: () => {
        res.end();
      },
    });

    // Отмена при disconnect
    res.on('close', () => {
      subscription.unsubscribe();
    });
  }

  // ─── Route preview ──────────────────────────────────────

  @Post('route')
  async routePreview(
    @Body() body: { message: string; expert?: string; experts?: string[] },
  ) {
    // Прямой вызов API
    const { data } = await this.nexus['http'].post(
      `${this.nexus['baseUrl']}/v1/chat/route`,
      body,
      this.nexus['axiosConfig'],
    ).toPromise();
    return data;
  }

  // ─── Documents ──────────────────────────────────────────

  @Get('projects/:projectId/documents')
  async listDocuments(@Param('projectId') projectId: string) {
    return this.nexus.listDocuments(projectId);
  }

  @Get('documents/:docId/status')
  async documentStatus(@Param('docId') docId: string) {
    return this.nexus.documentStatus(docId);
  }

  // ─── Conversations ──────────────────────────────────────

  @Post('projects/:projectId/conversations')
  async createConversation(
    @Param('projectId') projectId: string,
    @Body() body: { title?: string },
  ) {
    return this.nexus.createConversation(projectId, body.title);
  }

  @Get('conversations/:convId/messages')
  async getMessages(
    @Param('convId') convId: string,
    @Query('limit') limit: string,
    @Query('before_id') beforeId: string,
  ) {
    return this.nexus.getMessages(
      convId,
      limit ? parseInt(limit, 10) : 50,
      beforeId,
    );
  }

  // ─── Admin ──────────────────────────────────────────────

  @Get('admin/stats')
  async adminStats() {
    return this.nexus.adminStats();
  }

  @Get('admin/system-health')
  async systemHealth() {
    return this.nexus.systemHealth();
  }
}

// ---
// | KB @CerberRus00 - Nexus Invest Team
