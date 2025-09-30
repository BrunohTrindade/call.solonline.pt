<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class EventController extends Controller
{
    // SSE: notifica alterações nos contatos (usado para disparar refresh no cliente)
    public function streamContacts(Request $request)
    {
        $lastSent = null;
        $ttlSeconds = 600; // até 10 minutos por conexão

        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        return response()->stream(function () use (&$lastSent, $ttlSeconds) {
            $start = time();
            // envia um ping inicial
            echo "event: ping\n";
            echo 'data: {"hello":"ok"}' . "\n\n";
            @ob_flush(); @flush();

            while (!connection_aborted() && (time() - $start) < $ttlSeconds) {
                $current = Cache::get('contacts_last_change');
                if ($current && $current !== $lastSent) {
                    $payload = json_encode(['ts' => $current]);
                    echo "event: contacts\n";
                    echo 'data: ' . $payload . "\n\n";
                    $lastSent = $current;
                    @ob_flush(); @flush();
                }
                // heartbeats a cada 10s para manter conexão viva
                if (((time() - $start) % 10) === 0) {
                    echo "event: ping\n";
                    echo 'data: {}' . "\n\n";
                    @ob_flush(); @flush();
                }
                sleep(1);
            }
        }, 200, $headers);
    }

    // SSE: progresso de importação por jobId
    public function streamImport(Request $request, string $jobId)
    {
        $lastHash = null;
        $ttlSeconds = 3600; // até 1h para imports grandes
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        return response()->stream(function () use ($jobId, &$lastHash, $ttlSeconds) {
            $start = time();
            echo "event: ping\n";
            echo 'data: {"jobId":"'.addslashes($jobId).'"}' . "\n\n";
            @ob_flush(); @flush();
            while (!connection_aborted() && (time() - $start) < $ttlSeconds) {
                $state = Cache::get('import:progress:'.$jobId);
                if ($state) {
                    $json = json_encode($state);
                    $hash = md5($json);
                    if ($hash !== $lastHash) {
                        echo "event: progress\n";
                        echo 'data: ' . $json . "\n\n";
                        $lastHash = $hash;
                        @ob_flush(); @flush();
                        if (!empty($state['status']) && in_array($state['status'], ['done','error'])) {
                            break; // encerra quando concluir
                        }
                    }
                }
                sleep(1);
            }
        }, 200, $headers);
    }
}
