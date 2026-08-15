<?php

if (!class_exists('TerminalResponse')) {
	/**
	 * Sinal de "a resposta HTTP terminou aqui", usado SOMENTE em modo de teste.
	 *
	 * Em producao, basic_redir()/json_response()/array_to_csv() chamam exit() de
	 * verdade. Sob PHPUnit (constante TESTING definida em tests/bootstrap.php),
	 * eles lancam esta classe: o teste captura e inspeciona para onde a resposta
	 * ia, em vez de o runner morrer no exit().
	 *
	 * Estende Error, nao Exception, de proposito: os controllers usam
	 * `catch (RuntimeException $e)` e `catch (Exception $e)` em volta de
	 * operacoes de banco e de envio de e-mail. Se este sinal fosse uma Exception,
	 * um redirect disparado dentro de um try seria engolido pelo catch da
	 * aplicacao e o teste veria um resultado errado. Error passa por todos os
	 * catch existentes do projeto (verificado por grep em 2026-08-13: nenhum
	 * `catch (\Throwable)` nem `catch (Error)` envolve chamada de controller).
	 */
	final class TerminalResponse extends \Error
	{
		public const KIND_REDIRECT = 'redirect';
		public const KIND_JSON     = 'json';
		public const KIND_CSV      = 'csv';
		public const KIND_ERROR    = 'error';

		/** @param array<string, mixed> $payload */
		public function __construct(
			public readonly string $kind,
			public readonly array $payload = []
		) {
			parent::__construct(sprintf('TerminalResponse(%s)', $kind));
		}
	}
}
