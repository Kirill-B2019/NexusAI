/**
 * NEXUS AI — Nest.js клиент.
 *
 * Установка:
 *   npm install @nestjs/axios axios form-data rxjs
 *
 * Регистрация модуля — см. nexys-ai.module.ts
 *
 * Использование:
 *   constructor(private readonly nexus: NexusAiService) {}
 *
 *   const result = await this.nexus.chat({ message: 'Что такое API Gateway?', expert: 'system_architect' });
 *   console.log(result.content);
 */

import { Injectable, Logger } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { ConfigService } from '@nestjs/config';
import { AxiosResponse, AxiosRequestConfig } from 'axios';
import { firstValueFrom, Observable } from 'rxjs';
import FormData from 'form-data';
import * as fs from 'fs';

// ─── Типы ──────────────────────────────────────────────────

export interface ChatDto {
  message: string;
  expert?: string;
  experts?: string[];
  thinking?: boolean;
  project_id?: string;
  conversation_id?: string;
  orchestrate?: boolean;
  use_llm_aggregator?: boolean;
  save_to_conversation?: boolean;
  use_rag?: boolean;
  rag_top_k?: number;
  rag_min_score?: number;
  document_ids?: string[];
}

export interface Source {
  index: number;
  document_id: string;
  document_name?: string;
  chunk_index: number;
  score: number;
  preview?: string;
}

export interface ChatResponse {
  mode: 'single' | 'auto_orchestration' | 'manual_orchestration';
  expert?: string;
  content?: string;
  reasoning?: string;
  aggregated?: string;
  experts_used?: string[];
  experts_skipped?: Array<{ key: string; reason: string }>;
  route_reason?: string;
  route_method?: string;
  aggregator?: string;
  summary?: { total: number; ok: number; failed: number };
  sources?: Source[];
  elapsed_s: number;
  rag_used?: boolean;
  timings?: { predicted_per_second: number; predicted_n: number };
  message_ids?: { user: string; assistant: string };
}

export interface Expert {
  key: string;
  name: string;
  description?: string;
  icon?: string;
  color?: string;
  sort_order?: number;
}

export interface Project {
  id: string;
  external_id?: string;
  name: string;
  description?: string;
  created_at: string;
}

export interface Document {
  id: string;
  project_id: string;
  filename: string;
  original_filename: string;
  file_size: number;
  mime_type: string;
  status: 'pending' | 'processing' | 'ready' | 'failed';
  chunks_count: number;
  error?: string;
  created_at: string;
}

export interface SSEEvent {
  type: string;
  data: any;
}

// ─── Сервис ───────────────────────────────────────────────

@Injectable()
export class NexusAiService {
  private readonly logger = new Logger(NexusAiService.name);
  private readonly baseUrl: string;
  private readonly apiKey: string;
  private readonly timeout: number;

  constructor(
    private readonly http: HttpService,
    private readonly config: ConfigService,
  ) {
    this.baseUrl = (this.config.get<string>('NEXUS_AI_URL') ?? 'http://31.128.38.96/api').replace(/\/$/, '');
    this.apiKey = this.config.get<string>('NEXUS_AI_KEY') ?? '';
    this.timeout = this.config.get<number>('NEXUS_AI_TIMEOUT', 300_000);

    if (!this.apiKey) {
      this.logger.warn('NEXUS_AI_KEY не задан в .env');
    }
  }

  private get headers(): Record<string, string> {
    return {
      'X-API-Key': this.apiKey,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    };
  }

  private get axiosConfig(): AxiosRequestConfig {
    return { headers: this.headers, timeout: this.timeout };
  }

  // ─── System ─────────────────────────────────────────────

  async health(): Promise<{ status: string }> {
    const { data } = await firstValueFrom(
      this.http.get(`${this.baseUrl}/health`, this.axiosConfig),
    );
    return data;
  }

  async version(): Promise<any> {
    const { data } = await firstValueFrom(
      this.http.get(`${this.baseUrl}/version`, this.axiosConfig),
    );
    return data;
  }

  // ─── Experts ────────────────────────────────────────────

  async listExperts(enabledOnly = true): Promise<Expert[]> {
    const url = enabledOnly ? '/v1/experts' : '/v1/experts/all';
    const { data } = await firstValueFrom(
      this.http.get(`${this.baseUrl}${url}`, this.axiosConfig),
    );
    return data.experts ?? [];
  }

  // ─── Projects ───────────────────────────────────────────

  async listProjects(): Promise<Project[]> {
    const { data } = await firstValueFrom(
      this.http.get(`${this.baseUrl}/v1/projects`, this.axiosConfig),
    );
    return data.projects ?? [];
  }

  async createProject(name: string, externalId?: string): Promise<Project> {
    const payload: Record<string, any> = { name };
    if (externalId) payload.external_id = externalId;

    const { data } = await firstValueFrom(
      this.http.post(`${this.baseUrl}/v1/projects`, payload, this.axiosConfig),
    );
    return data;
  }

  // ─── API Keys ───────────────────────────────────────────

  async createApiKey(
    projectId: string,
    name: string,
    options: { allowed_experts?: string[]; rate_limit_per_min?: number } = {},
  ): Promise<{ key: string; key_prefix: string; warning: string }> {
    const { data } = await firstValueFrom(
      this.http.post(
        `${this.baseUrl}/v1/projects/${projectId}/keys`,
        { name, ...options },
        this.axiosConfig,
      ),
    );
    return data;
  }

  // ─── Documents ──────────────────────────────────────────

  async uploadDocument(projectId: string, filePath: string): Promise<Document> {
    const form = new FormData();
    form.append('file', fs.createReadStream(filePath));

    const { data } = await firstValueFrom(
      this.http.post(
        `${this.baseUrl}/v1/projects/${projectId}/documents`,
        form,
        {
          headers: { ...this.headers, ...form.getHeaders() },
          timeout: this.timeout,
        },
      ),
    );
    return data;
  }

  async listDocuments(projectId: string): Promise<Document[]> {
    const { data } = await firstValueFrom(
      this.http.get(
        `${this.baseUrl}/v1/projects/${projectId}/documents`,
        this.axiosConfig,
      ),
    );
    return data.documents ?? [];
  }

  async documentStatus(documentId: string): Promise<Document> {
    const { data } = await firstValueFrom(
      this.http.get(
        `${this.baseUrl}/v1/documents/${documentId}/status`,
        this.axiosConfig,
      ),
    );
    return data;
  }

  // ─── Chat ───────────────────────────────────────────────

  async chat(dto: ChatDto): Promise<ChatResponse> {
    const payload: ChatDto = {
      ...dto,
      orchestrate: dto.orchestrate ?? (!dto.expert && !dto.experts),
    };

    const { data } = await firstValueFrom(
      this.http.post<ChatResponse>(
        `${this.baseUrl}/v1/chat`,
        payload,
        this.axiosConfig,
      ),
    );
    return data;
  }

  /**
   * SSE-стрим. Возвращает Observable, который emits события.
   *
   * Использование:
   *   this.nexus.streamChat(dto).subscribe({
   *     next: (event) => {
   *       if (event.type === 'token') process.stdout.write(event.data.delta);
   *     },
   *     complete: () => console.log('\nГотово'),
   *   });
   */
  streamChat(dto: ChatDto): Observable<SSEEvent> {
    const self = this;
    return new Observable<SSEEvent>((subscriber) => {
      const controller = new AbortController();

      (async () => {
        try {
          const response = await fetch(`${self.baseUrl}/v1/chat/stream`, {
            method: 'POST',
            headers: self.headers,
            body: JSON.stringify(dto),
            signal: controller.signal,
          });

          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }

          const reader = response.body!.getReader();
          const decoder = new TextDecoder();
          let buffer = '';

          while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const events = buffer.split('\n\n');
            buffer = events.pop() ?? '';

            for (const ev of events) {
              const typeMatch = ev.match(/^event: (.+)$/m);
              const dataMatch = ev.match(/^data: (.+)$/m);
              if (!dataMatch) continue;

              subscriber.next({
                type: typeMatch ? typeMatch[1] : 'message',
                data: JSON.parse(dataMatch[1]),
              });
            }
          }

          subscriber.complete();
        } catch (err) {
          subscriber.error(err);
        }
      })();

      // Cleanup
      return () => controller.abort();
    });
  }

  // ─── Диалоги ────────────────────────────────────────────

  async createConversation(projectId: string, title?: string): Promise<any> {
    const { data } = await firstValueFrom(
      this.http.post(
        `${this.baseUrl}/v1/projects/${projectId}/conversations`,
        title ? { title } : {},
        this.axiosConfig,
      ),
    );
    return data;
  }

  async getMessages(
    conversationId: string,
    limit = 50,
    beforeId?: string,
  ): Promise<any> {
    const params: Record<string, any> = { limit };
    if (beforeId) params.before_id = beforeId;

    const { data } = await firstValueFrom(
      this.http.get(
        `${this.baseUrl}/v1/conversations/${conversationId}/messages`,
        { ...this.axiosConfig, params },
      ),
    );
    return data;
  }

  // ─── Admin ──────────────────────────────────────────────

  async adminStats(): Promise<any> {
    const { data } = await firstValueFrom(
      this.http.get(`${this.baseUrl}/v1/admin/stats`, this.axiosConfig),
    );
    return data;
  }

  async systemHealth(): Promise<any> {
    const { data } = await firstValueFrom(
      this.http.get(`${this.baseUrl}/v1/admin/system-health`, this.axiosConfig),
    );
    return data;
  }
}

// ---
// | KB @CerberRus00 - Nexus Invest Team
