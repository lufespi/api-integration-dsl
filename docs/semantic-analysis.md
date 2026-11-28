# Análise semântica

Especificação do `Analyzer` (`src/Semantic/Analyzer.php`), a etapa entre o
Parser e o Generator. Resolve a pendência **P2** de
[`ast-contract.md`](ast-contract.md).

## 1. Objetivo

A gramática ([`grammar.md`](grammar.md)) garante a *forma* do programa, mas não
o seu *significado*. Por exemplo, este programa é sintaticamente correto e o
Parser o aceita, mas não faz sentido:

```text
integration copiarUsuarios {
    from Users.listUsers;
    to Crm.createContact;      // a API Crm nunca foi declarada
}
```

O Analyzer percorre a AST e rejeita esse tipo de programa **antes** da geração
de código. Assim o Generator pode assumir as garantias 3 a 6 de
[`ast-contract.md`](ast-contract.md), seção 4, sem precisar verificá-las.

```text
Parser -> AST -> Analyzer -> AST (a mesma, verificada) -> Generator
```

## 2. Regras verificadas

Todas as mensagens têm o formato
`Erro semântico na linha L, coluna C: <descrição>`. A posição é a do elemento
do programa que causa o erro (a palavra-chave `api`, `endpoint`,
`integration` ou `header`; o nome do campo; o nome da API em `Api.endpoint`; o
primeiro identificador de uma referência; o nome de uma função).

| # | Regra | Exemplo de descrição |
|---|---|---|
| S1 | Nomes de API são únicos | `API 'Users' já declarada na linha 1, coluna 1` |
| S2 | Nomes de endpoint são únicos dentro da API | `endpoint 'list' já declarado na API 'Shop' (linha 3, coluna 5)` |
| S3 | Nomes de cabeçalho são únicos dentro da API, sem diferenciar maiúsculas | `cabeçalho 'accept' repetido na API 'Shop': já declarado como 'Accept' (linha 3, coluna 5); nomes de cabeçalho HTTP não diferenciam maiúsculas de minúsculas` |
| S4 | Nomes de integração são únicos | `integração 'sync' já declarada na linha 13, coluna 1` |
| S5 | Campos do `map` são únicos dentro da integração | `campo 'name' repetido no bloco 'map' da integração 'sync' (primeira ocorrência na linha 17, coluna 9)` |
| S6 | `from` e `to` citam uma API declarada | `API 'Crm' não declarada na cláusula 'to' da integração 'sync'; APIs declaradas: Users` |
| S7 | `from` e `to` citam um endpoint declarado **naquela** API | `a API 'Users' não declara o endpoint 'createContact' (cláusula 'from' da integração 'sync'); endpoints de 'Users': listUsers, getUser` |
| S8 | Sem `as`, nenhum campo pode ser referenciado em `when`/`map` | `a integração 'sync' usa o campo 'status' do registro de origem, mas a cláusula 'from' não declara alias; escreva 'from Users.listUsers as nome'` |
| S9 | Com `as`, todo caminho de campo começa pelo alias | `a referência 'order.total' deve começar pelo alias 'user' da integração 'sync'` |
| S10 | Cabeçalhos não referenciam campos (não há registro de origem) | `o cabeçalho 'X-Token' da API 'Shop' referencia 'token', mas cabeçalhos não têm acesso a registros da origem; para ler um segredo use env("NOME")` |
| S11 | Funções chamadas existem | `função desconhecida 'capitalize'; funções disponíveis: env, lower, now, upper` |
| S12 | Funções recebem o número certo de argumentos | `a função 'env' espera 1 argumento, mas recebeu 2 argumentos` |

Detalhes que valem para todas as regras:

- **A ordem das declarações não importa.** Uma integração pode aparecer antes
  das APIs que referencia.
- **Em nomes repetidos, vale a primeira declaração.** A segunda é o erro, e as
  referências continuam resolvendo para a primeira, evitando erros em cascata.
- **Os nomes diferenciam maiúsculas de minúsculas**, exceto os de cabeçalho
  (S3), que seguem o HTTP.
- **S8 é relatado uma vez por integração**, na primeira referência a campo,
  porque todas as demais têm a mesma causa.
- **O alias sozinho é válido** (`user`, sem caminho): representa o registro
  inteiro. Palavras reservadas como nome de campo (`user.from`) também.
- **Argumentos de funções e operandos de operadores também são verificados**:
  `upper(person.name)` e `!other.flag` geram erro de S9.
- **Não há recuperação em modo pânico.** Diferente do Parser, o Analyzer
  percorre o programa inteiro e relata *todos* os erros, ordenados por
  posição no fonte (e não pela ordem em que foram verificados).

## 3. Decisões tomadas

| # | Questão | Decisão |
|---|---|---|
| P2 | Quem faz a verificação semântica? | Etapa separada `Analyzer` entre Parser e Generator |
| P12 | Nome do registro quando `as` é omitido | Não há nome implícito: `as` é opcional, mas **só enquanto nenhum campo for referenciado** (S8). Logo, se `Integration.alias` é `null`, o Generator pode assumir que `condition` e `mappings` não contêm `FieldReference` |
| P7 (provisória) | Funções embutidas | `env/1`, `upper/1`, `lower/1`, `now/0`, em `Analyzer::DEFAULT_FUNCTIONS`. **O Generator deve confirmar ou alterar esta lista** |

A tabela de funções é um parâmetro do construtor
(`new Analyzer(['env' => 1, 'slug' => 1])`), então mudar o conjunto de funções
não exige mexer nas regras. O essencial é que o Analyzer e o Generator
conheçam as **mesmas** funções: uma função aceita aqui e sem tradução no
Generator só falharia na geração.

## 4. Uso

```php
$tokens  = (new Lexer($source))->tokenize();
$program = (new Parser($tokens))->parse();
$program = (new Analyzer())->analyze($program);   // devolve o mesmo Program
$php     = (new PhpGenerator())->generate($program);
```

`analyze()` lança `App\Exceptions\SemanticException`, subclasse de
`CompilerException`, com todos os erros em `errors()` (lista de
`App\Semantic\SemanticError`). Com um único erro, `getMessage()` é a mensagem
do erro; com vários, `N erros semânticos encontrados:` seguido de uma linha
por erro (mesmo formato da `SyntaxException`). `getSourceLine()` e
`getSourceColumn()` apontam o primeiro erro. Uma CLI que já trata
`CompilerException` só precisa imprimir `getMessage()` e sair com
`EXIT_COMPILATION_ERROR`.

## 5. O que **não** é verificado

Decisões em aberto, não esquecimentos:

- **Compatibilidade de tipos.** `age: int = "abc"` é aceito. Os tipos
  declarados no `map` (`string`, `int`, `float`, `bool`) orientam a conversão
  no Generator; o tipo dos campos de um JSON recebido só é conhecido em tempo
  de execução.
- **Método HTTP de origem e destino.** Nada impede `from` apontar para um
  `DELETE` ou `to` para um `GET`. Se o grupo quiser restringir (por exemplo,
  origem somente `GET`), é uma regra a mais neste documento.
- **Existência dos campos no JSON** e **uso das APIs declaradas**
  (API sem nenhuma integração não gera aviso).
- **Variáveis de ambiente** citadas em `env("...")`: só existem na execução.

## 6. Testes

- `tests/Unit/Semantic/AnalyzerTest.php`: cada regra S1–S12, com mensagem,
  linha e coluna; ordem das declarações; tabela de funções; reutilização do
  Analyzer. As posições esperadas são calculadas a partir do texto do próprio
  programa de teste.
- `tests/Unit/Semantic/AnalyzerExamplesTest.php`: todo exemplo de
  `examples/valid/` passa na análise semântica; todo exemplo de
  `examples/invalid/semantic/` é aceito pelo Parser e rejeitado pelo Analyzer
  com exatamente os erros das linhas `// Erro esperado:` do próprio arquivo.
