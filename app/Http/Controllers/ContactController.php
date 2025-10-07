<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class ContactController extends Controller
{
    // Estatísticas: total, processados, pendentes com ETag
    public function stats(Request $request)
    {
        // ETag rápido baseado apenas no marcador global de mudanças
        $cc = Cache::get('contacts_last_change');
        $fastEtag = 'W/"stats-v2-'.md5('cc:'.($cc ?? 'null')).'"';
        if ($request->header('If-None-Match') === $fastEtag) {
            // Guard: se o cache indicar >0 mas a tabela ficou vazia por fora do app (ex: limpeza manual),
            // devolve estatísticas zeradas imediatamente para evitar 304 enganoso.
            $cached = Cache::get('contacts_stats');
            $cachedTotal = is_array($cached) ? (int)($cached['total'] ?? 0) : 0;
            if ($cachedTotal > 0) {
                $totalNow = (int) Contact::count();
                if ($totalNow === 0) {
                    $data = ['total' => 0, 'processed' => 0, 'pending' => 0];
                    Cache::put('contacts_stats', $data, 10);
                    return response()->json($data)
                        ->header('Cache-Control','private, max-age=10, no-transform')
                        ->header('ETag', $fastEtag);
                }
            }
            // Nada mudou desde a última resposta: evita consultas caras
            return response('', 304, ['ETag' => $fastEtag]);
        }

        $data = Cache::remember('contacts_stats', (int) config('performance.contacts_stats_cache_ttl', 10), function(){
            $total = Contact::count();
            $processed = Contact::whereNotNull('processed_at')->count();
            return [
                'total' => $total,
                'processed' => $processed,
                'pending' => max(0, $total - $processed),
            ];
        });

        return response()->json($data)
            ->header('Cache-Control','private, max-age=10, no-transform')
            ->header('ETag', $fastEtag);
    }

    // Lista paginada com filtros e ETag
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));
        $status = $request->input('status');
        $qb = Contact::query();
        if ($search !== '') {
            $useFulltext = (bool) config('performance.contacts_search_fulltext', false);
            $driver = DB::getDriverName();
            if ($useFulltext && in_array($driver, ['mysql','mariadb'], true)) {
                // Transforma busca em termos com prefixo (BOOLEAN MODE) para acelerar e aproximar do LIKE
                $terms = preg_split('/\s+/', trim($search));
                $terms = array_values(array_filter(array_map(function($t){
                    $t = trim($t);
                    return strlen($t) >= 2 ? '+' . addcslashes($t, '+-><()~*:\"@') . '*': '';
                }, $terms)));
                if (!empty($terms)) {
                    $against = implode(' ', $terms);
                    $qb->whereRaw('MATCH (nome,email,empresa,telefone) AGAINST (? IN BOOLEAN MODE)', [$against]);
                } else {
                    // Fallback para LIKE se termos inválidos
                    $qb->where(function($w) use ($search) {
                        $like = '%'.$search.'%';
                        $w->where('nome', 'like', $like)
                          ->orWhere('email', 'like', $like)
                          ->orWhere('empresa', 'like', $like)
                          ->orWhere('telefone', 'like', $like)
                          ->orWhere('nif', 'like', $like);
                    });
                }
            } else {
                $qb->where(function($w) use ($search) {
                    $like = '%'.$search.'%';
                    $w->where('nome', 'like', $like)
                      ->orWhere('email', 'like', $like)
                      ->orWhere('empresa', 'like', $like)
                      ->orWhere('telefone', 'like', $like)
                      ->orWhere('nif', 'like', $like);
                });
            }
        }
        if ($status === 'pending') {
            $qb->whereNull('processed_at');
        } elseif ($status === 'processed') {
            $qb->whereNotNull('processed_at');
        }

    // Aceita per_page, perPage ou limit
    $perPage = (int) ($request->input('per_page', $request->input('perPage', $request->input('limit', 50))));
        if ($perPage < 1) { $perPage = 1; }
        if ($perPage > 100) { $perPage = 100; }

        $lastChangeMarker = Cache::get('contacts_last_change');
        $currentPage = (int) max(1, (int) $request->input('page', 1));
        // ETag rápido: não depende de COUNT/ MAX. Conservador: muda a cada alteração global.
        $fastPayload = implode('|', [
            'page:'.$currentPage,
            'per:'.$perPage,
            'status:'.($status ?? ''),
            'q:'.$search,
            'cc:'.($lastChangeMarker ?? 'null'),
        ]);
        $fastEtag = 'W/"contacts-v2-'.md5($fastPayload).'"';
        if ($request->header('If-None-Match') === $fastEtag) {
            // Se a tabela tiver sido esvaziada (ex: exclusão em massa), evite 304 enganoso
            // somente quando página 1 e sem busca para não custar caro em todos os cenários
            if ($currentPage === 1 && $search === '' && (empty($status) || $status === 'all')) {
                $totalNow = (int) Contact::count();
                if ($totalNow === 0) {
                    $empty = [
                        'data' => [],
                        'links' => [],
                        'meta' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => $perPage,
                            'total' => 0,
                        ],
                    ];
                    return response()->json($empty)
                        ->header('Cache-Control','private, max-age=5, no-transform')
                        ->header('ETag', $fastEtag);
                }
            }
            // Nada mudou desde a última resposta para este conjunto de filtros/página
            return response('', 304, ['ETag' => $fastEtag]);
        }

        // Microcache de listagem: chave considera filtros/página e marcador global de mudanças
        $ttl = (int) config('performance.contacts_index_cache_ttl', 5);
        $cacheKey = 'contacts:index:'.md5(json_encode([
            'page' => $currentPage,
            'per' => $perPage,
            'status' => $status,
            'q' => $search,
            'cc' => $lastChangeMarker,
        ]));

        $resp = Cache::remember($cacheKey, $ttl, function () use ($qb, $perPage, $currentPage) {
            return (clone $qb)
                ->select(['id','numero','nome','email','empresa','telefone','nif','processed_at','created_at','observacao','info_adicional'])
                // Usa ordenação pelo campo indexado 'numero' (com fallback para id)
                ->orderByRaw('CASE WHEN numero IS NULL THEN 1 ELSE 0 END')
                ->orderBy('numero')
                ->orderBy('id')
                ->paginate($perPage, ['*'], 'page', $currentPage);
        });

        return response()->json($resp)
            ->header('Cache-Control','private, max-age=5, no-transform')
            ->header('ETag', $fastEtag);
    }

    public function show(Contact $contact)
    {
        return $contact;
    }

    // Atualiza observação e marca como processado (primeira gravação). Após isso, usuários comuns só podem usar info_adicional.
    public function update(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'observacao' => ['nullable','string'],
            'info_adicional' => ['nullable','string'],
        ]);

        $user = $request->user();
        $isAdmin = (bool) optional($user)->is_admin;

        // Regras:
        // - Antes da primeira gravação (processed_at == null): pode escrever em 'observacao'.
        //   Ao escrever texto não vazio pela primeira vez, marca processed_at/processed_by.
        // - Depois de processed_at != null: somente admin pode alterar 'observacao'.
        //   Usuários comuns devem usar 'info_adicional'.

        $wantsToChangeObs = array_key_exists('observacao', $data);
        $newObs = $wantsToChangeObs ? (string)($data['observacao'] ?? '') : ($contact->observacao ?? '');
        $newObsTrim = trim($newObs);

        if ($contact->processed_at !== null && $wantsToChangeObs && !$isAdmin) {
            // Bloqueia alteração de observação por não-admin após primeira gravação
            unset($data['observacao']);
        }

        // Aplicar mudanças permitidas
        if (array_key_exists('observacao', $data)) {
            $contact->observacao = $newObs;
        }

        if (array_key_exists('info_adicional', $data)) {
            $contact->info_adicional = (string)($data['info_adicional'] ?? '');
        }

        // Primeira gravação: marcar processado quando observacao tiver conteúdo pela primeira vez
        if ($newObsTrim !== '' && $contact->processed_at === null && $wantsToChangeObs) {
            $contact->processed_at = now();
            $contact->processed_by = optional($user)->id;
        }

        $contact->save();
        Cache::forget('contacts_stats');
        Cache::put('contacts_last_change', now()->toIso8601String(), now()->addDays(30));
        return $contact->refresh();
    }

    // Exclui e renumera
    public function destroy(Request $request, Contact $contact)
    {
        DB::beginTransaction();
        try {
            $numero = $contact->numero;
            $contact->delete();
            if (!is_null($numero)) {
                DB::update('update contacts set numero = numero - 1 where numero > ?', [ $numero ]);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        Cache::forget('contacts_stats');
        Cache::put('contacts_last_change', now()->toIso8601String(), now()->addDays(30));
        return response()->json([ 'deleted' => true ]);
    }

    // Importação síncrona (CSV/TXT/XLS/XLSX) sem validação de aprovação
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required','file','mimes:csv,txt,xls,xlsx','max:102400'], // 100 MB
        ]);
        $file = $request->file('file');
        $path = $file->getRealPath();
        $ext = strtolower($file->getClientOriginalExtension());
        $rows = 0; $created = 0; $updated = 0;

        $processRow = function(array $assoc) use (&$rows, &$created, &$updated, $request) {
            $assocNorm = [];
            foreach ($assoc as $k => $v) {
                if ($k === null) { continue; }
                $assocNorm[strtolower(trim($k))] = is_string($v) ? trim($v) : $v;
            }
            $payload = [
                'empresa' => array_key_exists('empresa', $assocNorm) ? (string)$assocNorm['empresa'] : '',
                'nome' => array_key_exists('nome', $assocNorm) ? (string)$assocNorm['nome'] : '',
                'telefone' => array_key_exists('telefone', $assocNorm) ? (string)$assocNorm['telefone'] : '',
                'email' => array_key_exists('email', $assocNorm) ? (string)$assocNorm['email'] : '',
                'nif' => isset($assocNorm['nif']) && $assocNorm['nif'] !== '' ? (string)$assocNorm['nif'] : null,
            ];
            $rules = [
                'empresa' => ['nullable','string','max:255'],
                'nome' => ['nullable','string','max:255'],
                'telefone' => ['nullable','string','max:255'],
                'email' => ['nullable','email','max:255'],
                'nif' => ['nullable','string','max:255'],
            ];
            $v = Validator::make($payload, $rules);
            if ($v->fails()) { return; }
            if (trim(($payload['empresa'] ?? '').($payload['nome'] ?? '').($payload['telefone'] ?? '').($payload['email'] ?? '')) === '') { return; }

            $rows++;
            $contact = null;
            if (!empty($payload['email'])) {
                $contact = Contact::where('email', $payload['email'])->first();
            }
            if ($contact) {
                // Atualiza apenas campos não vazios para não apagar dados existentes
                $updateData = [];
                foreach (['empresa','nome','telefone','nif'] as $field) {
                    $val = $payload[$field] ?? null;
                    if (is_string($val)) { $val = trim($val); }
                    if ($val !== null && $val !== '') { $updateData[$field] = $val; }
                }
                // Por padrão, não alteramos o e-mail via import para não quebrar a chave de busca
                if (!empty($updateData)) {
                    $contact->fill($updateData);
                    $contact->save();
                    $updated++;
                }
            } else {
                $c = new Contact($payload);
                // Registros importados devem iniciar como pendentes: não marcar como processados aqui
                $c->processed_at = null;
                $c->processed_by = null;
                $maxNumero = (int) Contact::max('numero');
                $c->numero = $maxNumero > 0 ? $maxNumero + 1 : (int) Contact::count() + 1;
                $c->save();
                $created++;
            }
        };

        $map = [
            'empresa' => strtolower(trim((string)($request->input('map_empresa') ?? 'empresa'))),
            'nome' => strtolower(trim((string)($request->input('map_nome') ?? 'nome'))),
            'telefone' => strtolower(trim((string)($request->input('map_telefone') ?? 'telefone'))),
            'email' => strtolower(trim((string)($request->input('map_email') ?? 'email'))),
            'nif' => strtolower(trim((string)($request->input('map_nif') ?? 'nif'))),
        ];

        if (in_array($ext, ['csv','txt'])) {
            $handle = fopen($path, 'r');
            if (!$handle) { return response()->json(['message' => 'Não foi possível ler o arquivo'], 422); }
            $firstLine = fgets($handle);
            if ($firstLine === false) { fclose($handle); return response()->json(['message' => 'CSV vazio'], 422); }
            $delims = [",", ";", "\t"]; $bestDelim = ","; $bestCount = substr_count($firstLine, $bestDelim);
            foreach ($delims as $d) { $c = substr_count($firstLine, $d); if ($c > $bestCount) { $bestCount = $c; $bestDelim = $d; } }
            rewind($handle);
            $header = fgetcsv($handle, 0, $bestDelim);
            if (!$header || count($header) === 0) { fclose($handle); return response()->json(['message' => 'CSV sem cabeçalho válido'], 422); }
            $headerLower = array_map(fn($h) => strtolower(trim((string)$h)), $header);
            $idx = array_flip($headerLower);

            DB::beginTransaction();
            try {
                while (($data = fgetcsv($handle, 0, $bestDelim)) !== false) {
                    if ($data === null) { continue; }
                    if (count($data) == 1 && trim((string)$data[0]) === '') { continue; }
                    $assoc = [
                        'empresa' => array_key_exists($map['empresa'], $idx) ? ($data[$idx[$map['empresa']]] ?? '') : '',
                        'nome' => array_key_exists($map['nome'], $idx) ? ($data[$idx[$map['nome']]] ?? '') : '',
                        'telefone' => array_key_exists($map['telefone'], $idx) ? ($data[$idx[$map['telefone']]] ?? '') : '',
                        'email' => array_key_exists($map['email'], $idx) ? ($data[$idx[$map['email']]] ?? '') : '',
                        'nif' => array_key_exists($map['nif'], $idx) ? ($data[$idx[$map['nif']]] ?? null) : null,
                    ];
                    $processRow($assoc);
                }
                fclose($handle);
                DB::commit();
            } catch (\Throwable $e) {
                fclose($handle);
                DB::rollBack();
                throw $e;
            }
        } else { // xls/xlsx
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $rowsIter = $sheet->toArray(null, true, true, false);
            if (empty($rowsIter)) { return response()->json(['message' => 'Planilha vazia'], 422); }
            $headerLower = array_map(fn($h) => strtolower(trim((string)$h)), $rowsIter[0]);
            $idx = array_flip($headerLower);

            DB::beginTransaction();
            try {
                $rowCount = count($rowsIter);
                for ($i = 1; $i < $rowCount; $i++) {
                    $data = $rowsIter[$i];
                    if (!is_array($data)) { continue; }
                    $assoc = [
                        'empresa' => array_key_exists($map['empresa'], $idx) ? ($data[$idx[$map['empresa']]] ?? '') : '',
                        'nome' => array_key_exists($map['nome'], $idx) ? ($data[$idx[$map['nome']]] ?? '') : '',
                        'telefone' => array_key_exists($map['telefone'], $idx) ? ($data[$idx[$map['telefone']]] ?? '') : '',
                        'email' => array_key_exists($map['email'], $idx) ? ($data[$idx[$map['email']]] ?? '') : '',
                        'nif' => array_key_exists($map['nif'], $idx) ? ($data[$idx[$map['nif']]] ?? null) : null,
                    ];
                    foreach ($assoc as $k => $v) { if ($v === null) { $assoc[$k] = ''; } }
                    $processRow($assoc);
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        Cache::forget('contacts_stats');
        Cache::put('contacts_last_change', now()->toIso8601String(), now()->addDays(30));
        return response()->json([
            'rows' => $rows,
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    // Inicia importação em background via Job e retorna jobId
    public function importBackground(Request $request)
    {
        $request->validate([
            'file' => ['required','file','mimes:csv,txt,xls,xlsx','max:512000'],
        ]);
        $file = $request->file('file');
        $storedPath = $file->storeAs('imports', uniqid('import_').'.'.$file->getClientOriginalExtension(), 'local');
        $jobId = uniqid('job_', true);

        $map = [
            'empresa' => strtolower(trim((string)($request->input('map_empresa') ?? 'empresa'))),
            'nome' => strtolower(trim((string)($request->input('map_nome') ?? 'nome'))),
            'telefone' => strtolower(trim((string)($request->input('map_telefone') ?? 'telefone'))),
            'email' => strtolower(trim((string)($request->input('map_email') ?? 'email'))),
            'nif' => strtolower(trim((string)($request->input('map_nif') ?? 'nif'))),
        ];

        Cache::put('import:progress:'.$jobId, [
            'status' => 'queued',
            'uploaded' => true,
            'created' => 0,
            'updated' => 0,
            'rows' => 0,
            'message' => null,
        ], 3600);

        \App\Jobs\ImportContactsJob::dispatch($storedPath, $map, $jobId, optional($request->user())->id);
        return response()->json(['jobId' => $jobId], 202);
    }
}
