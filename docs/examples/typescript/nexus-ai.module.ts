/**
 * NEXUS AI — Nest.js модуль.
 *
 * Регистрация в app.module.ts:
 *   import { NexusAiModule } from './nexus-ai/nexus-ai.module';
 *
 *   @Module({
 *     imports: [
 *       ConfigModule.forRoot(),
 *       NexusAiModule,
 *     ],
 *   })
 *   export class AppModule {}
 */

import { Module } from '@nestjs/common';
import { HttpModule } from '@nestjs/axios';
import { NexusAiService } from './nexus-ai.service';
import { NexusAiController } from './nexus-ai.controller';

@Module({
  imports: [
    HttpModule.register({
      timeout: 300_000,
      maxRedirects: 5,
    }),
  ],
  providers: [NexusAiService],
  controllers: [NexusAiController],
  exports: [NexusAiService],
})
export class NexusAiModule {}

// ---
// | KB @CerberRus00 - Nexus Invest Team
