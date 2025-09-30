<?php

namespace App\Jobs;

use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $path;
    public array $map;
    public string $jobId;
    public ?int $userId;

    public function __construct(string $path, array $map, string $jobId, ?int $userId)
    {
        $this->path = $path;
        $this->map = $map;
        $this->jobId = $jobId;
        $this->userId = $userId;
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $fullPath = storage_path('app/'.$this->path);
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $rows = 0; $created = 0; $updated = 0;
        $update = function($status, $msg=null) use (&$rows,&$created,&$updated) {
            Cache::put('import:progress:'.$this->jobId, [
                'status' => $status,
                'rows' => $rows,
                'created' => $created,
                'updated' => $updated,
                'message' => $msg,
            ], 3600);
        };
        $update('processing');

        $processRow = function(array $assoc) use (&$rows,&$created,&$updated) {
            $assocNorm = [];
            foreach ($assoc as $k => $v) { if ($k !== null) { $assocNorm[strtolower(trim($k))] = is_string($v) ? trim($v) : $v; } }
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
            if (!empty($payload['email'])) { $contact = Contact::where('email', $payload['email'])->first(); }
            if ($contact) {
                // Atualiza apenas campos não vazios para não apagar dados existentes
                $updateData = [];
                foreach (['empresa','nome','telefone','nif'] as $field) {
                    $val = $payload[$field] ?? null;
                    if (is_string($val)) { $val = trim($val); }
                    if ($val !== null && $val !== '') { $updateData[$field] = $val; }
                }
                if (!empty($updateData)) {
                    $contact->fill($updateData);
                    $contact->save();
                    $updated++;
                }
            } else {
                $c = new Contact($payload);
                // Registros importados devem iniciar como pendentes
                $c->processed_at = null;
                $c->processed_by = null;
                $maxNumero = (int) Contact::max('numero');
                $c->numero = $maxNumero > 0 ? $maxNumero + 1 : (int) Contact::count() + 1;
                $c->save();
                $created++;
            }
        };

        try {
            if (in_array($ext, ['csv','txt'])) {
                $handle = fopen($fullPath, 'r');
                if (!$handle) { $update('error','Não foi possível ler o arquivo'); return; }
                $firstLine = fgets($handle);
                if ($firstLine === false) { fclose($handle); $update('error','CSV vazio'); return; }
                $delims = [",",";","\t"]; $bestDelim = ","; $bestCount = substr_count($firstLine, $bestDelim);
                foreach ($delims as $d) { $c = substr_count($firstLine, $d); if ($c > $bestCount) { $bestCount = $c; $bestDelim = $d; } }
                rewind($handle);
                $header = fgetcsv($handle, 0, $bestDelim);
                if (!$header || count($header) === 0) { fclose($handle); $update('error','CSV sem cabeçalho'); return; }
                $headerLower = array_map(fn($h) => strtolower(trim((string)$h)), $header);
                $idx = array_flip($headerLower);
                DB::beginTransaction();
                try {
                    $batch = 0;
                    while (($data = fgetcsv($handle, 0, $bestDelim)) !== false) {
                        if ($data === null) { continue; }
                        if (count($data) == 1 && trim((string)$data[0]) === '') { continue; }
                        $assoc = [
                            'empresa' => array_key_exists($this->map['empresa'], $idx) ? ($data[$idx[$this->map['empresa']]] ?? '') : '',
                            'nome' => array_key_exists($this->map['nome'], $idx) ? ($data[$idx[$this->map['nome']]] ?? '') : '',
                            'telefone' => array_key_exists($this->map['telefone'], $idx) ? ($data[$idx[$this->map['telefone']]] ?? '') : '',
                            'email' => array_key_exists($this->map['email'], $idx) ? ($data[$idx[$this->map['email']]] ?? '') : '',
                            'nif' => array_key_exists($this->map['nif'], $idx) ? ($data[$idx[$this->map['nif']]] ?? null) : null,
                        ];
                        $processRow($assoc);
                        $batch++;
                        if ($batch % 500 === 0) { DB::commit(); $update('processing'); DB::beginTransaction(); }
                    }
                    fclose($handle);
                    DB::commit();
                } catch (\Throwable $e) {
                    fclose($handle);
                    DB::rollBack();
                    throw $e;
                }
            } else {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fullPath);
                $sheet = $spreadsheet->getActiveSheet();
                $rowsIter = $sheet->toArray(null, true, true, false);
                if (empty($rowsIter)) { $update('error','Planilha vazia'); return; }
                $headerLower = array_map(fn($h) => strtolower(trim((string)$h)), $rowsIter[0]);
                $idx = array_flip($headerLower);
                DB::beginTransaction();
                try {
                    $rowCount = count($rowsIter);
                    for ($i = 1; $i < $rowCount; $i++) {
                        $data = $rowsIter[$i];
                        if (!is_array($data)) { continue; }
                        $assoc = [
                            'empresa' => array_key_exists($this->map['empresa'], $idx) ? ($data[$idx[$this->map['empresa']]] ?? '') : '',
                            'nome' => array_key_exists($this->map['nome'], $idx) ? ($data[$idx[$this->map['nome']]] ?? '') : '',
                            'telefone' => array_key_exists($this->map['telefone'], $idx) ? ($data[$idx[$this->map['telefone']]] ?? '') : '',
                            'email' => array_key_exists($this->map['email'], $idx) ? ($data[$idx[$this->map['email']]] ?? '') : '',
                            'nif' => array_key_exists($this->map['nif'], $idx) ? ($data[$idx[$this->map['nif']]] ?? null) : null,
                        ];
                        foreach ($assoc as $k => $v) { if ($v === null) { $assoc[$k] = ''; } }
                        $processRow($assoc);
                        if ($i % 500 === 0) { DB::commit(); $update('processing'); DB::beginTransaction(); }
                    }
                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
            $update('done');
            Cache::forget('contacts_stats');
            Cache::put('contacts_last_change', now()->toIso8601String(), now()->addDays(30));
        } catch (\Throwable $e) {
            Cache::put('import:progress:'.$this->jobId, [ 'status' => 'error', 'message' => $e->getMessage() ], 3600);
        }
    }
}
