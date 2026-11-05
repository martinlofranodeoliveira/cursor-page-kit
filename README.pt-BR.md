# Cursor Page Kit

Software PHP original da [Lofrano Tecnologia](https://lofranotecnologia.com.br). Repositório pretendido: [martinlofranodeoliveira/cursor-page-kit](https://github.com/martinlofranodeoliveira/cursor-page-kit). Versão 0.1.0 ainda não lançada. MIT, copyright 2026 Lofrano Tecnologia.

[English](README.md)

Biblioteca pequena de paginação progressiva por chave composta e exemplo CLI com PDO/SQLite. Valores de ordenação repetidos são desempatados por um ID positivo e único. Demonstra uma leitura consistente em um snapshot, sem criar outro painel CRUD. Não usa pacotes Composer, APIs em execução, telemetria, rede, credenciais ou dados pessoais reais.

## Instalação e execução

Requer PHP 8.4+ de 64 bits, PDO_SQLite e SQLite 3.51.3+. Extraia o projeto e execute na raiz; não há etapa de instalação de dependências PHP. Os testes de subprocessos exigem `proc_open`, que não é utilizado pela biblioteca nem pela CLI.

```sh
php -v
php -r 'echo "bits=", PHP_INT_SIZE * 8, " pdo_sqlite=", extension_loaded("pdo_sqlite") ? "yes" : "no", PHP_EOL;'
php tests/run.php
php bin/cursor-page.php demo
php bin/cursor-page.php demo --json
php bin/cursor-page.php --help
```

O Dockerfile opcional verifica PDO_SQLite, já incluído na imagem oficial utilizada. A construção precisa obter a imagem; testes e demonstração funcionam offline e sem privilégios:

```sh
docker build -t cursor-page-kit .
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=32m cursor-page-kit php tests/run.php
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=32m cursor-page-kit php bin/cursor-page.php demo
```

A tag da imagem é mutável; não há promessa de builds de contêiner idênticos byte a byte.

## Demonstração determinística

A demonstração cria um arquivo SQLite temporário privado com nove títulos inventados, chaves repetidas e os dois extremos dos inteiros com sinal. Usa **uma única transação de leitura nas três páginas**, verificando a ordem esperada dos IDs, a unicidade e o encerramento. Sucesso normal e falhas capturáveis removem o arquivo e seus auxiliares. Encerramento abrupto ou falta de energia pode deixar um arquivo temporário.

Saída humana completa:

```text
Cursor Page Kit: synthetic snapshot demo
page=1 ids=9,2,4 has_more=true
page=2 ids=1,3,6 has_more=true
page=3 ids=5,7,8 has_more=false
total_items=9 unique_items=9
```

`demo --json` retorna um objeto com `pages`, `total_items` e `unique_items`, incluindo as três páginas completas e ambos os totais iguais a 9. O consumidor JSON deve preservar inteiros de 64 bits; números JavaScript não representam exatamente todos os valores suportados.

## Contrato do banco e comando list

O esquema conhecido é uma tabela comum com rowid no banco `main`, com exatamente estas colunas nesta ordem, sem defaults nem colunas geradas:

```sql
CREATE TABLE items(
    id INTEGER PRIMARY KEY,
    sort_key INTEGER NOT NULL,
    title TEXT NOT NULL
);
CREATE INDEX items_order ON items(sort_key, id);
```

O índice é recomendado, não obrigatório. `id` também pode declarar `NOT NULL`. Exige-se um alias de rowid; views, tabelas virtuais e `WITHOUT ROWID` são rejeitadas. Outras tabelas e índices são permitidos. `STRICT` é opcional. A validação dos metadados não certifica todas as constraints ou todo o conteúdo do banco.

Cada registro deve conter `id` inteiro positivo, `sort_key` inteiro de 64 bits com sinal e `title` TEXT UTF-8 de até 1.024 bytes. Os registros selecionados, inclusive o registro adicional de verificação, são validados. Não há varredura completa de validade.

Para criar um arquivo novo com dados **sintéticos**, este comando recusa um caminho já existente:

```sh
php -r '$h = @fopen("example.sqlite", "x"); if ($h === false) { fwrite(STDERR, "example.sqlite must not exist\n"); exit(2); } fclose($h); $db = new PDO("sqlite:example.sqlite"); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->exec("CREATE TABLE items(id INTEGER PRIMARY KEY, sort_key INTEGER NOT NULL, title TEXT NOT NULL); INSERT INTO items VALUES(1, 0, char(65)), (2, 0, char(66))");'
php bin/cursor-page.php list --db example.sqlite --page-size 2 --json
```

Saída completa desse comando list:

```json
{
    "items": [
        {
            "id": 1,
            "sort_key": 0,
            "title": "A"
        },
        {
            "id": 2,
            "sort_key": 0,
            "title": "B"
        }
    ],
    "has_more": false,
    "next_cursor": null
}
```

`list --db FILE [--page-size N] [--cursor TOKEN] [--json]` lê uma página. O tamanho padrão é 20, limitado a 1..100. Para continuar, passe em `--cursor` exatamente o `next_cursor` não nulo retornado anteriormente. A saída humana coloca títulos entre aspas e escapa caracteres de controle do terminal.

O modo list resolve um arquivo local regular explicitamente indicado e abre SQLite com **READONLY sem CREATE**, além de ativar `query_only`. Não cria, migra nem escreve conteúdo no banco. Arquivos inexistentes, não SQLite ou com esquema inesperado falham. SQLite pode participar da coordenação normal de locks/leitores WAL; não é um sandbox de sistema de arquivos nem um leitor de arquivo-snapshot imutável. Use arquivos SQLite e sistema de arquivos local confiáveis. Links simbólicos são resolvidos; substituição concorrente de arquivos está fora do contrato.

Código de saída 0: página válida, demonstração ou ajuda. Código 2: erro de uso, ambiente, E/S, esquema, dados ou cursor. Diagnósticos em stderr não incluem caminhos, títulos, cursores completos ou mensagens brutas do PDO. A saída de sucesso contém intencionalmente títulos e cursores de continuação.

## Uso da biblioteca

```php
<?php
declare(strict_types=1);
require 'src/bootstrap.php';

use CursorPageKit\Cursor;
use CursorPageKit\Pager;
use CursorPageKit\SqliteDatabase;

$token = Cursor::encode(-4, 12);
$position = Cursor::decode($token); // sortKey=-4, id=12
$pdo = SqliteDatabase::openReadOnly('example.sqlite');
$pdo->beginTransaction();
try {
    $page = (new Pager($pdo))->page(2); // segundo argumento opcional: cursor
    $pdo->commit();
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
```

`KeysetPredicate::after(?CursorPosition)` fornece `sql()` e `bind(PDOStatement)` para os nomes fixos `sort_key` e `id`. Não recebe fragmentos SQL nem identificadores arbitrários. Valores de cursor e limites são vinculados como inteiros PDO.

## Cursor e páginas

O cursor v1 é **base64url estrito sem padding** dos bytes exatos:

```json
{"v":1,"sort_key":0,"id":1}
```

A ordem das propriedades, ausência de espaços e grafia decimal canônica são obrigatórias. Os bytes decodificados devem ser iguais à recodificação canônica; isso também rejeita propriedades duplicadas. Limite: 128 bytes ASCII por token. `sort_key`: -9223372036854775808 a 9223372036854775807. `id`: 1 a 9223372036854775807. Padding, bits não utilizados diferentes de zero, propriedades ausentes/extras, booleanos, floats, grafias alternativas e valores fora da faixa são rejeitados.

Toda página tem `items`, `has_more` e `next_cursor`. A ordenação é `sort_key ASC, id ASC`. A consulta busca tamanho mais um e, se existir continuação, aponta para o último item **retornado**, não para o adicional. Páginas finais/vazias têm `has_more: false` e `next_cursor: null`. Ausência de cursor inicia a leitura; string vazia é inválida. Pare ao receber null: enviar null novamente reinicia a paginação.

**Cursores são transporte, não autenticação, criptografia ou autorização.** Qualquer pessoa pode construir uma tupla válida. A aplicação deve autorizar cada consulta e restringir o conjunto de dados. O cursor v1 não contém tenant, filtros, validade temporal ou identidade de snapshot.

**Limite de concorrência:** SQLite fixa o snapshot de uma transação de leitura adiada na primeira leitura. Mantenha todas as páginas na mesma transação controlada pelo chamador para obter a travessia estável demonstrada. Invocações separadas de list usam transações separadas. Inserções, exclusões e mudanças de ordenação entre transações podem alterar a travessia, causando omissões ou reaparições. Não há promessa de processamento exatamente uma vez sob mutação concorrente.

Detalhes em [contrato](docs/contract.md), [projeto](docs/design.md), [segurança](SECURITY.md) e [contribuição](CONTRIBUTING.md). Implementa-se somente o subconjunto SQLite documentado, não um padrão geral de paginação SQL nem uma certificação para produção.

## Codificação do banco

O banco SQLite deve usar UTF-8. Bancos UTF-16 são recusados antes da leitura dos registros, pois a conversão de TEXT para BLOB usa a codificação do banco e a ferramenta limita e emite os títulos em UTF-8. Essa restrição evita alterações silenciosas nos títulos.

## Runtime SQLite

Use a [compilação com checksum e qualificação por SQL](docs/sqlite-runtime.md). A aplicação exige SQLite 3.51.3+ realmente vinculado; a imagem fornecida usa 3.54.1. Compile com `docker build -t cursor-page-kit-sqlite .` e execute os comandos documentados de teste/demo com `docker run --rm --network none cursor-page-kit-sqlite <argv>`. Instalações nativas devem fornecer SQLite na faixa suportada.
