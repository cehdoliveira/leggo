#!/bin/bash
# Falha se arquivos compartilhados entre manager/ e site/ divergirem.
# app/inc/lib e app/inc/model DEVEM ser identicos entre os dois ambientes.
# controller/, public_html/index.php, urls.php, kernel.php e ui/ NAO sao
# compartilhados (divergencia intencional) e portanto nao sao checados aqui,
# com excecao de public_html/assets/css/tokens.css (ver abaixo).
#
# vendor/ e ignorado (gitignored / symlinked, fora do versionamento).
# tests/ e ignorado (os bootstraps diferem apenas por HTTP_HOST).
set -e

# Roda a partir da raiz do repositorio, independente de onde foi invocado.
cd "$(git rev-parse --show-toplevel)"

status=0
for sub in app/inc/lib app/inc/model; do
    if ! diff -rq --exclude=vendor --exclude=tests "manager/$sub" "site/$sub" > /dev/null; then
        echo "DRIFT em $sub entre manager/ e site/:"
        diff -rq --exclude=vendor --exclude=tests "manager/$sub" "site/$sub" || true
        status=1
    fi
done

# tokens.css e o unico arquivo de public_html/ espelhado entre os ambientes:
# e o contrato visual do whitelabel (rebrand edita um arquivo so).
if ! diff -q "manager/public_html/assets/css/tokens.css" "site/public_html/assets/css/tokens.css" > /dev/null; then
    echo "DRIFT em public_html/assets/css/tokens.css entre manager/ e site/:"
    diff "manager/public_html/assets/css/tokens.css" "site/public_html/assets/css/tokens.css" || true
    status=1
fi

exit $status
