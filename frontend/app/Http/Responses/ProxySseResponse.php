<?php

namespace App\Http\Responses;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ProxySseResponse
{
    public static function fromUpstream(Response $upstream): StreamedResponse
    {
        return response()->stream(function () use ($upstream): void {
            @set_time_limit(0);
            $body = $upstream->toPsrResponse()->getBody();
            $eventBuffer = '';
            $bufferedBytes = 0;

            try {
                while (! $body->eof() && ! connection_aborted()) {
                    $chunk = $body->read(1);

                    if ($chunk === '') {
                        usleep(1_000);

                        continue;
                    }

                    $eventBuffer .= $chunk;
                    $bufferedBytes++;

                    if (str_ends_with($eventBuffer, "\n\n") || $bufferedBytes >= 65_536) {
                        echo $eventBuffer;
                        $eventBuffer = '';
                        $bufferedBytes = 0;

                        if (ob_get_level() > 0) {
                            ob_flush();
                        }

                        flush();
                    }
                }

                if ($eventBuffer !== '' && ! connection_aborted()) {
                    echo $eventBuffer;
                    flush();
                }
            } catch (Throwable $exception) {
                Log::warning('NEXUS AI SSE stream was interrupted.', [
                    'exception' => $exception::class,
                ]);

                if (! connection_aborted()) {
                    echo "event: error\ndata: {\"error\":\"Поток ответа NEXUS AI прерван.\"}\n\n";
                    flush();
                }
            } finally {
                $body->close();
            }
        }, $upstream->status(), [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
