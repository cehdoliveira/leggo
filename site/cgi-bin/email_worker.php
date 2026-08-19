#!/usr/bin/env php
<?php
/**
 * email_worker.php
 *
 * Consumidor da fila de e-mail em Redis Streams. Lê do consumer group,
 * envia via EmailQueue::deliver() e só dá XACK depois do envio confirmado
 * (at-least-once). Entradas não confirmadas ficam no pending list e são
 * reprocessadas — no boot e a cada ~60s de ociosidade.
 *
 * Uso: php email_worker.php   (o crontab levanta com flock -n)
 */

date_default_timezone_set('America/Sao_Paulo');

$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"]     = "leggo.local";

putenv('SERVER_PORT=80');
putenv('SERVER_PROTOCOL=http');
putenv('SERVER_NAME=' . $_SERVER["HTTP_HOST"]);
putenv('SCRIPT_NAME=index.php');

set_include_path($_SERVER["DOCUMENT_ROOT"] . PATH_SEPARATOR . get_include_path());

define('CLI_MODE', true);

require_once __DIR__ . '/../app/inc/kernel.php';
require_once __DIR__ . '/../app/inc/lib/vendor/autoload.php';
require_once __DIR__ . '/../app/inc/lib/EmailQueue.php';

/** Máximo de entregas antes de tratar a entrada como poison e descartar. */
const MAX_DELIVERIES = 5;

function log_message(string $message, string $level = 'INFO'): void
{
    echo '[' . date('Y-m-d H:i:s') . "] [{$level}] {$message}\n";
}

/** Processa uma entrada: true = pode dar ACK. */
function process_entry(string $id, array $fields): bool
{
    $payload = json_decode($fields['payload'] ?? '', true);

    if (!is_array($payload) || empty($payload['to']) || empty($payload['subject'])) {
        log_message("Entrada {$id} inválida/poison, descartada.", 'WARNING');
        return true; // ACK: nunca vai dar certo, não pode ficar no pending para sempre
    }

    if (EmailQueue::deliver($payload)) {
        log_message("Email enviado para " . implode(', ', $payload['to']));
        return true;
    }

    log_message("Falha no envio da entrada {$id} — sem ACK, será reprocessada.", 'ERROR');
    return false;
}

/** Reprocessa o pending list deste consumer; descarta o que passou de MAX_DELIVERIES. */
function drain_pending(object $redis, string $key, string $group, string $consumer): void
{
    $pending = $redis->xPending($key, $group, '-', '+', 100);
    if (!is_array($pending)) {
        return;
    }

    foreach ($pending as $entry) {
        // [0]=id, [1]=consumer, [2]=idle ms, [3]=deliveries
        $id = (string) ($entry[0] ?? '');
        if ($id === '' || (int) ($entry[3] ?? 0) <= MAX_DELIVERIES) {
            continue;
        }
        log_message("Entrada {$id} excedeu " . MAX_DELIVERIES . " entregas, descartada.", 'ERROR');
        $redis->xAck($key, $group, [$id]);
        $redis->xDel($key, [$id]);
    }

    // '0' = releitura das entradas já entregues a este consumer e ainda sem ACK.
    $claimed = $redis->xReadGroup($group, $consumer, [$key => '0'], 50, 1000);
    foreach (($claimed[$key] ?? []) as $id => $fields) {
        if (process_entry((string) $id, $fields)) {
            $redis->xAck($key, $group, [$id]);
            $redis->xDel($key, [$id]);
        }
    }
}

$redis = EmailQueue::connect();
if ($redis === null) {
    log_message('Sem conexão com o Redis. Encerrando; o cron tenta de novo.', 'ERROR');
    exit(1);
}

$key      = EmailQueue::streamKey();
$group    = EmailQueue::group();
$consumer = EmailQueue::consumer();

try {
    // MKSTREAM: cria o stream se ainda não existe. BUSYGROUP = já criado, ok.
    $redis->xGroup('CREATE', $key, $group, '0', true);
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
        log_message('Falha ao criar consumer group: ' . $e->getMessage(), 'ERROR');
        exit(1);
    }
}

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () { log_message('SIGTERM, encerrando.'); exit(0); });
    pcntl_signal(SIGINT,  function () { log_message('SIGINT, encerrando.');  exit(0); });
}

log_message("Worker iniciado — stream {$key}, grupo {$group}, consumer {$consumer}");
drain_pending($redis, $key, $group, $consumer);

$idleCycles = 0;

while (true) {
    try {
        $messages = $redis->xReadGroup($group, $consumer, [$key => '>'], 10, 5000);

        $entries = $messages[$key] ?? [];
        if ($entries === []) {
            $idleCycles++;
            // ~60s de ociosidade: tenta de novo o que falhou e ficou pendente.
            if ($idleCycles >= 12) {
                $idleCycles = 0;
                drain_pending($redis, $key, $group, $consumer);
            }
            continue;
        }

        $idleCycles = 0;
        foreach ($entries as $id => $fields) {
            if (process_entry((string) $id, $fields)) {
                $redis->xAck($key, $group, [$id]);
                $redis->xDel($key, [$id]);
            }
        }
    } catch (Throwable $e) {
        log_message('Erro no loop do worker: ' . $e->getMessage(), 'ERROR');
        sleep(5);
    }
}
