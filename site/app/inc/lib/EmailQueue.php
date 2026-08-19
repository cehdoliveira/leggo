<?php

/**
 * EmailQueue.php
 *
 * Fila de envio de e-mail sobre Redis Streams (XADD / XREADGROUP / XACK).
 *
 * O enfileiramento é BUFFERIZADO: enqueue() só acumula em memória. O despacho
 * para o stream acontece em flushPending(), chamado DEPOIS do commit da
 * transação global (basic_redir / close_request_transaction). Em rollback o
 * buffer é descartado por discardPending() — assim nenhum e-mail sai para uma
 * operação revertida.
 *
 * Fail-open: se o Redis estiver indisponível o e-mail é enviado inline via
 * PHPMailer, dentro do orçamento de tempo da request. Nunca é engolido em
 * silêncio.
 *
 * @package Leggo
 */

if (class_exists('EmailQueue', false)) {
    return;
}

class EmailQueue
{
    /**
     * Orçamento de tempo do fallback inline. max_execution_time é 30s
     * (docker/interface/php.ini:388); 20s deixa margem para o resto da request.
     * Acima disso o que sobrar é logado como ERRO e não enviado.
     */
    private const FALLBACK_BUDGET_SECONDS = 20;

    /** @var array<int, array<string, mixed>> */
    private static array $pending = [];

    /** @var array<int, array<string, mixed>> despachados sob TESTING (ver flushPending) */
    private static array $sent = [];

    private static ?object $redis = null;

    private static bool $connectFailed = false;

    /**
     * Enfileira um e-mail para despacho após o commit. Sempre devolve true:
     * a aceitação no buffer não é o mesmo que entrega, e o resultado real do
     * despacho só existe depois que a resposta já foi decidida.
     */
    public static function enqueue(string|array $to, string $subject, string $body, bool $isHtml = true): bool
    {
        self::$pending[] = [
            'to'        => is_array($to) ? array_values($to) : [$to],
            'subject'   => $subject,
            'body'      => $body,
            'isHtml'    => $isHtml,
            'timestamp' => time(),
        ];

        return true;
    }

    /** Descarta o buffer — chamado quando a transação global sofre rollback. */
    public static function discardPending(): void
    {
        self::$pending = [];
    }

    /**
     * Despacha o buffer. Chamado DEPOIS do commit. Nunca lança: uma falha aqui
     * não pode derrubar uma request cuja escrita já foi comitada.
     */
    public static function flushPending(): void
    {
        $items         = self::$pending;
        self::$pending = [];

        if ($items === []) {
            return;
        }

        // Sob TESTING não toca em Redis nem em SMTP: a CI não tem nenhum dos dois
        // (.github/workflows/ci.yml) e um envio real a partir da suíte mandaria
        // e-mail para as fixtures. Os testes assertam em EmailQueue::sent().
        if (defined('TESTING') && constant('TESTING')) {
            foreach ($items as $item) {
                self::$sent[] = $item;
            }
            return;
        }

        $start = microtime(true);

        foreach ($items as $item) {
            if (self::push($item)) {
                continue;
            }

            if ((microtime(true) - $start) > self::FALLBACK_BUDGET_SECONDS) {
                error_log('EmailQueue: orçamento do fallback estourado, e-mail NÃO enviado: '
                    . $item['subject'] . ' -> ' . implode(',', $item['to']));
                continue;
            }

            error_log('EmailQueue: Redis indisponível, enviando inline (fallback).');
            if (!self::deliver($item)) {
                error_log('EmailQueue: FALHA TOTAL, e-mail nem enfileirado nem enviado: '
                    . $item['subject'] . ' -> ' . implode(',', $item['to']));
            }
        }
    }

    /** XADD de um item. false = não entrou no stream (chamador cai no fallback). */
    private static function push(array $item): bool
    {
        try {
            $redis = self::connect();
            if ($redis === null) {
                return false;
            }

            $payload = json_encode($item);
            if ($payload === false) {
                error_log('EmailQueue: payload não serializável, descartado.');
                return true; // não adianta tentar inline: o dado é que está torto
            }

            $id = $redis->xAdd(self::streamKey(), '*', ['payload' => $payload], self::maxLen(), true);

            return is_string($id) && $id !== '';
        } catch (Throwable $e) {
            error_log('EmailQueue::push Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Conexão própria com o Redis — sem serializer e sem prefixo, ao contrário do
     * RedisCache (que liga OPT_SERIALIZER/OPT_PREFIX e não expõe streams).
     * Usada também pelo worker (cgi-bin/email_worker.php).
     */
    public static function connect(): ?object
    {
        if (self::$redis !== null) {
            return self::$redis;
        }
        if (self::$connectFailed || !extension_loaded('redis')) {
            return null;
        }

        try {
            $redisClass = 'Redis';
            $redis      = new $redisClass();

            $host = defined('REDIS_HOST') ? constant('REDIS_HOST') : 'redis';
            $port = defined('REDIS_PORT') ? (int) constant('REDIS_PORT') : 6379;

            if (!$redis->connect($host, $port, 2.5)) {
                throw new Exception('Falha ao conectar ao Redis');
            }

            $password = defined('REDIS_PASSWORD') ? constant('REDIS_PASSWORD') : '';
            if (!empty($password)) {
                $redis->auth($password);
            }

            $redis->select(defined('REDIS_DATABASE') ? (int) constant('REDIS_DATABASE') : 0);

            self::$redis = $redis;
            return self::$redis;
        } catch (Throwable $e) {
            error_log('EmailQueue::connect Error: ' . $e->getMessage());
            self::$connectFailed = true;
            return null;
        }
    }

    public static function streamKey(): string
    {
        return defined('EMAIL_STREAM_KEY') ? (string) constant('EMAIL_STREAM_KEY') : 'leggo:emails';
    }

    public static function group(): string
    {
        return defined('EMAIL_STREAM_GROUP') ? (string) constant('EMAIL_STREAM_GROUP') : 'leggo-email-worker';
    }

    public static function consumer(): string
    {
        return defined('EMAIL_STREAM_CONSUMER') ? (string) constant('EMAIL_STREAM_CONSUMER') : 'worker-1';
    }

    public static function maxLen(): int
    {
        return defined('EMAIL_STREAM_MAXLEN') ? (int) constant('EMAIL_STREAM_MAXLEN') : 10000;
    }

    /**
     * Envio real via PHPMailer. Usado pelo worker e pelo fallback inline.
     * Configuração SMTP vem das constantes mail_* do kernel.php.
     */
    public static function deliver(array $payload): bool
    {
        $mail = null;

        try {
            $mailerClass = '\PHPMailer\PHPMailer\PHPMailer';
            $mail        = new $mailerClass(true);

            $mail->isSMTP();
            $mail->Host       = defined('mail_from_host') ? constant('mail_from_host') : 'localhost';
            $mail->SMTPAuth   = true;
            $mail->Username   = defined('mail_from_user') ? constant('mail_from_user') : '';
            $mail->Password   = defined('mail_from_pwd') ? constant('mail_from_pwd') : '';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = defined('mail_from_port') ? (int) constant('mail_from_port') : 587;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 10;

            $mail->setFrom(
                defined('mail_from_mail') ? constant('mail_from_mail') : 'noreply@localhost',
                defined('mail_from_name') ? constant('mail_from_name') : 'Leggo'
            );

            foreach ($payload['to'] as $recipient) {
                $mail->addAddress($recipient);
            }

            $mail->isHTML((bool) ($payload['isHtml'] ?? true));
            $mail->Subject = (string) $payload['subject'];
            $mail->Body    = (string) $payload['body'];
            if ($payload['isHtml'] ?? true) {
                $mail->AltBody = strip_tags((string) $payload['body']);
            }

            return (bool) $mail->send();
        } catch (Throwable $e) {
            $errorInfo = $mail !== null ? $mail->ErrorInfo : $e->getMessage();
            error_log('EmailQueue::deliver Error: ' . $errorInfo);
            return false;
        }
    }

    // ===== Apoio a teste (só usados sob TESTING) =====

    /** @return array<int, array<string, mixed>> */
    public static function pending(): array
    {
        return self::$pending;
    }

    /** @return array<int, array<string, mixed>> */
    public static function sent(): array
    {
        return self::$sent;
    }

    public static function reset(): void
    {
        self::$pending = [];
        self::$sent    = [];
    }
}
